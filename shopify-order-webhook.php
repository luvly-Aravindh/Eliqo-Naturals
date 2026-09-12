<?php
/* =============================================================================
   SHOPIFY  orders/paid  ->  GETNOS DESK  forwarder
   -----------------------------------------------------------------------------
   Receives the Shopify "Order payment" (orders/paid) webhook, verifies it is
   genuinely from Shopify (HMAC), reshapes the order into the same Desk lead
   format the landing page sends, and forwards it as a COMPLETED purchase.

   The inbox then holds two records per buyer:
     - "checkout_started"    (sent by checkout.html when they begin checkout)
     - "purchase_completed"  (sent by THIS file when payment succeeds)
   Match the two on email / phone to see who paid and who abandoned.

   Deploy this on the same PHP host as your other Desk endpoints, then point a
   Shopify webhook at its public URL (see SETUP notes at the bottom).
============================================================================= */

require_once __DIR__ . '/desk-lib.php';   /* db(), send_inbox_email(), pending_mark_completed(), SUBJECT_SUCCESS */

/* ---------------------------------------------------------------------------
   CONFIG  — prefer environment variables; the constants are fallbacks.
--------------------------------------------------------------------------- */

/* Shopify webhook signing secret.
   Shopify admin -> Settings -> Notifications -> Webhooks ->
   "Your webhooks will be signed with ..." (the secret shown at the bottom).
   This ONE secret verifies every admin-created webhook on the store. */
$SHOPIFY_WEBHOOK_SECRET = getenv('SHOPIFY_WEBHOOK_SECRET') ?: 'fae6b2ed01356b93f09f5128850b6b5a1966735cb717fbe8fbd2ad79873778d4';

/* Desk endpoint + key and the DB connection come from desk-lib.php. */

/* Same Shopify VARIANT ids as checkout.html — lets the completed record mirror
   the started record's fields (jar count + add-on Yes/No). Keep in sync. */
$VARIANTS = array(
  'ghee'      => '44507148845167',
  'khapali'   => '44507149205615',
  'groundnut' => '44507134361711',
  'jaggery'   => '44178189221999'
);


/* =============================================================================
   1. BASIC REQUEST GUARDS
============================================================================= */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

$rawBody = file_get_contents('php://input');

if ($rawBody === false || $rawBody === '') {
  http_response_code(400);
  exit('Empty body');
}


/* =============================================================================
   2. VERIFY IT IS REALLY FROM SHOPIFY  (HMAC over the RAW body)
   -----------------------------------------------------------------------------
   Never skip this — without it, anyone could POST fake "paid" orders to Desk.
============================================================================= */

$hmacHeader = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';

$calculated = base64_encode(
  hash_hmac('sha256', $rawBody, $SHOPIFY_WEBHOOK_SECRET, true)
);

if (!hash_equals($calculated, $hmacHeader)) {
  http_response_code(401);
  exit('HMAC verification failed');
}


/* =============================================================================
   3. ANSWER SHOPIFY FAST  (must be 200 within 5s, or it retries)
   -----------------------------------------------------------------------------
   Close the connection now, then forward to Desk afterwards so a slow Desk
   response never causes Shopify to retry / mark the webhook as failing.
============================================================================= */

http_response_code(200);
header('Content-Type: text/plain');
echo 'ok';

if (function_exists('fastcgi_finish_request')) {
  fastcgi_finish_request();
} else {
  /* Fallback for non-FPM setups: flush and let the rest run. */
  @ob_end_flush();
  @flush();
}


/* =============================================================================
   4. IDEMPOTENCY  (Shopify may deliver the same webhook more than once)
   -----------------------------------------------------------------------------
   Best-effort de-dupe on the webhook id so the inbox doesn't get duplicate
   "purchase_completed" rows. Swap the file marker for Redis/DB if you prefer.
============================================================================= */

$webhookId = $_SERVER['HTTP_X_SHOPIFY_WEBHOOK_ID'] ?? '';

if ($webhookId !== '') {
  $marker = sys_get_temp_dir() . '/shopify_wh_' . preg_replace('/[^A-Za-z0-9_-]/', '', $webhookId);
  /* x = create-exclusive; fails silently if already processed */
  $fh = @fopen($marker, 'x');
  if ($fh === false) {
    /* Already handled this delivery — stop. */
    return;
  }
  fclose($fh);
}


/* =============================================================================
   5. RESHAPE THE ORDER INTO THE DESK LEAD FORMAT
============================================================================= */

$order = json_decode($rawBody, true);

if (!is_array($order)) {
  error_log('Shopify webhook: could not decode order JSON');
  return;
}

/* -- shipping address (fall back to billing, then customer) -- */
$ship  = $order['shipping_address'] ?? $order['billing_address'] ?? array();
$cust  = $order['customer'] ?? array();

$first = $ship['first_name'] ?? ($cust['first_name'] ?? '');
$last  = $ship['last_name']  ?? ($cust['last_name']  ?? '');
$name  = trim($first . ' ' . $last);
if ($name === '' && !empty($ship['name'])) { $name = $ship['name']; }

$phone = $ship['phone']
       ?? ($cust['phone'] ?? ($order['phone'] ?? ''));

$email = $order['email']
       ?? ($cust['email'] ?? '');

/* -- money -- */
$currency = $order['currency'] ?? 'INR';
$totalRaw = $order['total_price'] ?? $order['current_total_price'] ?? '0';
$totalNum = (float) $totalRaw;
$totalStr = ($currency === 'INR' ? '₹' : $currency . ' ')
          . number_format($totalNum, ($totalNum == floor($totalNum) ? 0 : 2));

/* -- line items: human summary + reconstruct pack / add-ons via variant ids -- */
$items      = $order['line_items'] ?? array();
$byVariant  = array_flip($VARIANTS);   /* variant_id(string) => key */
$jars       = 0;
$addons     = array('khapali' => 'No', 'groundnut' => 'No', 'jaggery' => 'No');
$summary    = array();

foreach ($items as $li) {
  $qty  = (int) ($li['quantity'] ?? 1);
  $vid  = isset($li['variant_id']) ? (string) $li['variant_id'] : '';
  $title = $li['title'] ?? 'Item';
  if (!empty($li['variant_title']) && strtolower($li['variant_title']) !== 'default title') {
    $title .= ' (' . $li['variant_title'] . ')';
  }
  $summary[] = $qty . ' x ' . $title;

  if (isset($byVariant[$vid])) {
    $key = $byVariant[$vid];
    if ($key === 'ghee') { $jars += $qty; }
    else { $addons[$key] = 'Yes'; }
  }
}

/* order_ref if it ever arrives as a note attribute (not required; match on
   email/phone). Harmless to read if absent. */
$orderRef = '';
foreach (($order['note_attributes'] ?? array()) as $na) {
  if (($na['name'] ?? '') === 'order_ref') { $orderRef = $na['value'] ?? ''; }
}

$lead = array(
  'form'   => 'order',
  'status' => 'purchase_completed',

  /* Shopify identifiers */
  'shopify_order'    => $order['name'] ?? ('#' . ($order['order_number'] ?? '')),
  'shopify_order_id' => (string) ($order['id'] ?? ''),
  'financial_status' => $order['financial_status'] ?? '',
  'order_ref'        => $orderRef,

  /* Customer / shipping */
  'name'     => $name,
  'phone'    => $phone,
  'email'    => $email,
  'address1' => $ship['address1'] ?? '',
  'address2' => $ship['address2'] ?? '',
  'city'     => $ship['city'] ?? '',
  'state'    => $ship['province'] ?? '',
  'pin'      => $ship['zip'] ?? '',

  /* Order contents (mirrors the started record) */
  'pack'      => $jars . ' jar' . ($jars === 1 ? '' : 's'),
  'jars'      => (string) $jars,
  'khapali'   => $addons['khapali'],
  'groundnut' => $addons['groundnut'],
  'jaggery'   => $addons['jaggery'],
  'items'     => implode(', ', $summary),
  'total'     => $totalStr,

  'created_at' => $order['created_at'] ?? '',
  'source'     => 'shopify-webhook'
);


/* =============================================================================
   6. SUPPRESS PENDING  +  SEND SUCCESS EMAIL IMMEDIATELY
   -----------------------------------------------------------------------------
   Marking the tracker row completed is what guarantees the 15-minute pending
   email never fires for a buyer who paid. Then the success email goes out now
   (subject "New Lead Eliqo Natural - Order Confirmed"), with one quick retry.
============================================================================= */

$email = $lead['email'];
$phone = $lead['phone'];

pending_mark_completed($email, $phone);   /* cancels the pending email */

if (!send_inbox_email(SUBJECT_SUCCESS, $lead)) {
  usleep(400000); /* 0.4s */
  send_inbox_email(SUBJECT_SUCCESS, $lead);
}

/* =============================================================================
   SETUP  (one-time)
   -----------------------------------------------------------------------------
   1. Deploy this file to a public HTTPS URL on your PHP host, e.g.
        https://deskbackend.getnos.io/shopify-order-webhook.php
      (or wherever your other Desk endpoints live).

   2. Set the secrets on the server (env vars preferred over editing constants):
        SHOPIFY_WEBHOOK_SECRET   - from Shopify admin (step 4)
        DESK_KEY                 - the same Bearer key checkout.html uses
        DESK_URL                 - already defaults to the Desk /v1/lead endpoint

   3. In Shopify admin: Settings -> Notifications -> Webhooks -> Create webhook
        Event   : Order payment            (topic orders/paid)
        Format  : JSON
        URL     : the URL from step 1
        Version : latest stable
      Save.

   4. Copy the signing secret shown at the bottom of that Webhooks section into
      SHOPIFY_WEBHOOK_SECRET.

   5. Click "Send test" on the webhook, then confirm a "purchase_completed"
      lead appears in the Desk inbox. Reconcile against "checkout_started"
      leads on email / phone.
============================================================================= */
