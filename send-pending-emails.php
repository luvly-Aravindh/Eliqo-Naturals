<?php
/* =============================================================================
   send-pending-emails.php  —  the 15-minute abandonment job
   -----------------------------------------------------------------------------
   Run every minute from cron:
     * * * * * /usr/bin/php /path/to/send-pending-emails.php >> /var/log/eliqo-pending.log 2>&1

   Sends "New Lead Eliqo Natural - Checkout Pending" for every checkout that:
     - is still 'pending' (not completed by the Shopify webhook), AND
     - has not been emailed yet (notified = 0), AND
     - was started at least ABANDON_MINUTES ago.
   Each qualifying row is emailed exactly once (notified flag + unique email).

   Can also be triggered over HTTPS with ?key=CRON_KEY if you have no shell cron.
============================================================================= */

require_once __DIR__ . '/desk-lib.php';

/* ---- Access guard: allow CLI freely; require a secret over HTTP ------------ */
if (PHP_SAPI !== 'cli') {
  $CRON_KEY = getenv('CRON_KEY') ?: 'REPLACE_CRON_KEY';
  if (($_GET['key'] ?? '') !== $CRON_KEY) {
    http_response_code(403);
    exit('forbidden');
  }
  header('Content-Type: text/plain');
}

try {
  $pdo = db();

  $sel = $pdo->prepare(
    "SELECT * FROM checkout_pending
      WHERE status = 'pending'
        AND notified = 0
        AND created_at <= (NOW() - INTERVAL " . ABANDON_MINUTES . " MINUTE)
      ORDER BY created_at ASC
      LIMIT 200"
  );
  $sel->execute();
  $rows = $sel->fetchAll();

  $sent = 0;

  foreach ($rows as $r) {

    $lead = array(
      'form'      => 'order',
      'status'    => 'checkout_pending',
      'name'      => $r['name'],
      'phone'     => $r['phone'],
      'email'     => (strpos($r['email'], '@no-email.eliqo') === false) ? $r['email'] : '',
      'address1'  => $r['address1'],
      'address2'  => $r['address2'],
      'city'      => $r['city'],
      'state'     => $r['state'],
      'pin'       => $r['pin'],
      'pack'      => $r['pack'],
      'jars'      => $r['jars'],
      'khapali'   => $r['khapali'],
      'groundnut' => $r['groundnut'],
      'jaggery'   => $r['jaggery'],
      'total'     => $r['total'],
      'order_ref' => $r['order_ref'],
      'page'      => $r['page'],
      'source'    => 'eliqo-checkout-pending'
    );

    /* Re-check completion right before sending, to lose the race with a
       just-arrived Shopify webhook. */
    $chk = $pdo->prepare("SELECT status FROM checkout_pending WHERE id = :id");
    $chk->execute(array(':id' => $r['id']));
    if ($chk->fetchColumn() !== 'pending') { continue; }

    if (send_inbox_email(SUBJECT_PENDING, $lead)) {
      $upd = $pdo->prepare(
        "UPDATE checkout_pending
            SET notified = 1, notified_at = NOW(), status = 'notified'
          WHERE id = :id AND status = 'pending'"
      );
      $upd->execute(array(':id' => $r['id']));
      $sent++;
    }
  }

  echo date('c') . " pending-emails: scanned " . count($rows) . ", sent " . $sent . "\n";

} catch (Throwable $e) {
  error_log('send-pending-emails error: ' . $e->getMessage());
  echo date('c') . " ERROR: " . $e->getMessage() . "\n";
  exit(1);
}
