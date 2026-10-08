<?php

declare(strict_types=1);

namespace Strapi\Email\Routes\Validation;

use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodObject;

/**
 * Port of server/src/routes/validation/email.ts. Upstream keeps the Strapi instance it is given
 * without reading it; the PHP routes file builds the validator before Strapi exists, so it takes none.
 */
final class EmailRouteValidator
{
    public function sendEmailInput(): ZodObject
    {
        return z::object([
            'from' => z::string()->optional(),
            'to' => z::string(),
            'cc' => z::string()->optional(),
            'bcc' => z::string()->optional(),
            'replyTo' => z::string()->optional(),
            'subject' => z::string(),
            'text' => z::string(),
            'html' => z::string()->optional(),
        ])->catchall(z::string());
    }

    public function emailResponse(): ZodObject
    {
        return z::object([]);
    }
}
