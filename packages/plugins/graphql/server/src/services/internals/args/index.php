<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Args;

use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ArgDef;

/** Port of server/src/services/internals/args/index.ts */
final class Args
{
    public readonly ArgDef $SortArg;

    public readonly ArgDef $PaginationArg;

    public readonly ArgDef $PublicationStatusArg;

    /** @deprecated Use `PublicationFilterArg` instead. */
    public readonly ArgDef $HasPublishedVersionArg;

    public readonly ArgDef $PublicationFilterArg;

    public function __construct()
    {
        $this->SortArg = Sort::create();
        $this->PaginationArg = Pagination::create();
        $this->PublicationStatusArg = PublicationStatus::create();
        $this->HasPublishedVersionArg = HasPublishedVersion::create();
        $this->PublicationFilterArg = PublicationFilter::create();
    }
}
