<?php

declare(strict_types=1);

// plugins the data transfer does not touch stay out of the fixture
return static fn (): array => [
    'graphql' => ['enabled' => false],
    'documentation' => ['enabled' => false],
    'sentry' => ['enabled' => false],
];
