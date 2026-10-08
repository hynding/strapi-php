<?php

declare(strict_types=1);

// Port of server/src/config/email-templates/forgot-password.ts (lodash-template placeholders kept)

$subject = 'Reset password';

$html = <<<'HTML'
<p>We heard that you lost your password. Sorry about that!</p>

<p>But don’t worry! You can use the following link to reset your password:</p>

<p><%= url %></p>

<p>Thanks.</p>
HTML;

$text = <<<'TEXT'
We heard that you lost your password. Sorry about that!

But don’t worry! You can use the following link to reset your password:

<%= url %>

Thanks.
TEXT;

return ['subject' => $subject, 'text' => $text, 'html' => $html];
