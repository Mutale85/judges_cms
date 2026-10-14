Dear <?= $name ?>,

Someone (hopefully you) asked to reset the password for your <?= app_name() ?> account. To choose a new password, open this link within <?= (int) $minutes ?> minutes:

<?= $link ?>

You will still need the code from your authenticator app to sign in. If you did not ask for this, ignore this message: your password stays the same.

— <?= app_name() ?>
