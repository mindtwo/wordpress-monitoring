<?php

declare(strict_types=1);

namespace Mindtwo\Monitoring\WordPress\Collectors;

use Mindtwo\Monitoring\Collectors\AbstractCollector;
use Mindtwo\Monitoring\Contracts\TechnologyResolver;
use Mindtwo\Monitoring\Data\CollectionResult;
use Mindtwo\Monitoring\Technology\EndOfLifeTechnologyResolver;
use Mindtwo\Monitoring\WordPress\WordPress\WordPressApi;

/**
 * WordPress core itself as a first-class technology metric.
 */
final class WordPressCollector extends AbstractCollector
{
    private TechnologyResolver $technologies;

    public function __construct(
        private WordPressApi $wordPress,
        ?TechnologyResolver $technologies = null
    ) {
        $this->technologies = $technologies ?? EndOfLifeTechnologyResolver::default();
    }

    public function key(): string
    {
        return 'wordpress';
    }

    public function supported(): bool
    {
        return $this->wordPress->version() !== null;
    }

    public function collect(): CollectionResult
    {
        $version = $this->wordPress->version();

        if ($version === null) {
            return CollectionResult::unsupported($this->key());
        }

        return CollectionResult::ok($this->key(), $this->technologyData(
            $this->technologies->resolve('wordpress'),
            $version,
            ['multisite' => $this->wordPress->isMultisite()]
        ));
    }
}
