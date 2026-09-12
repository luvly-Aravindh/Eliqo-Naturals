<?php
/* =============================================================================
   desk-lib.php  —  shared config + helpers for the Eliqo checkout backend
   -----------------------------------------------------------------------------
   Used by:
     checkout-pending.php       (intake — records a started checkout)
     send-pending-emails.php    (cron   — emails abandoned checkouts at 15 min)
     shopify-order-webhook.php  (webhook — marks completed + success email)

   All inbox email goes out through the EXISTING Desk API (send_inbox_email),
   so there is one email path with a per-event subject line.
============================================================================= */

/* ---- Timing / dedupe ------------------------------------------------------ */
const ABANDON_MINUTES = 15;   /* wait this long before the pending email      */
const REARM_HOURS     = 24;   /* don't re-notify the same email within this   */

/* ---- Desk API (same endpoint + key the landing page uses) ----------------- */
$DESK_URL = getenv('DESK_URL') ?: 'https://deskbackend.getnos.io/v1/lead';
$DESK_KEY = getenv('DESK_KEY') ?: 'lh_CLhcQ2Rh546lS38dr8tAb_85ScJhsxYDynbzbCePDfE';

/* ---- Subjects ------------------------------------------------------------- */
const SUBJECT_PENDING = 'New Lead Eliqo Natural - Checkout Pending';
const SUBJECT_SUCCESS = 'New Lead Eliqo Natural - Order Confirmed';

/* ---- Database (PDO). MySQL by default; SQLite DSN also works. -------------- */
$DB_DSN  = getenv('DB_DSN')  ?: 'mysql:host=127.0.0.1;dbname=eliqo;charset=utf8mb4';
$DB_USER = getenv('DB_USER') ?: 'REPLACE_DB_USER';
$DB_PASS = getenv('DB_PASS') ?: 'REPLACE_DB_PASS';


/* =========================================================================== */

function db() {
  static $pdo = null;
  if ($pdo === null) {
    global $DB_DSN, $DB_USER, $DB_PASS;
    $pdo = new PDO($DB_DSN, $DB_USER, $DB_PASS, array(
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false
    ));
  }
  return $pdo;
}

/* Last 10 digits of a phone number, for tolerant matching (+91 etc). */
function p10($phone) {
  $d = preg_replace('/\D/', '', (string) $phone);
  return substr($d, -10);
}

/* Send one inbox notification through the existing Desk API. Returns true on 2xx. */
function send_inbox_email($subject, array $lead) {
  global $DESK_URL, $DESK_KEY;

  $lead['subject'] = $subject;   /* Desk uses this as the email subject */

  $ch = curl_init($DESK_URL);
  curl_setopt_array($ch, array(
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER     => array(
      'Content-Type: application/json',
      'Authorization: Bearer ' . $DESK_KEY
    ),
    CURLOPT_POSTFIELDS     => json_encode($lead)
  ));
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  if ($code < 200 || $code >= 300) {
    error_log('Desk email failed [' . $subject . '] http=' . $code
              . ' curl=' . $err . ' resp=' . substr((string) $resp, 0, 300));
    return false;
  }
  return true;
}

/* Mark a pending record completed so the cron never emails it. Match on email,
   then fall back to phone (last 10 digits). Safe to call more than once. */
function pending_mark_completed($email, $phone) {
  try {
    $pdo = db();

    if ($email !== '') {
      $st = $pdo->prepare(
        "UPDATE checkout_pending SET status='completed', completed_at=NOW()
         WHERE email = :e AND status <> 'completed'"
      );
      $st->execute(array(':e' => $email));
      if ($st->rowCount() > 0) { return true; }
    }

    $p = p10($phone);
    if ($p !== '') {
      $st = $pdo->prepare(
        "UPDATE checkout_pending SET status='completed', completed_at=NOW()
         WHERE RIGHT(phone,10) = :p AND status <> 'completed'"
      );
      $st->execute(array(':p' => $p));
      return $st->rowCount() > 0;
    }
  } catch (Throwable $e) {
    error_log('pending_mark_completed error: ' . $e->getMessage());
  }
  return false;
}
