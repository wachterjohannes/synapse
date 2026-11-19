<?php

namespace App\Brain;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\DocumentInterface;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Document\VectorizerInterface;

/**
 * Some embedding models, Cohere among them, embed a stored document and a search query in different modes. The
 * bundle's vectorizer uses the document mode, so the retriever gets this wrapper, which adds the query options
 * of the provider (see config/providers/). Providers without a query mode pass an empty list.
 */
final class QueryVectorizer implements VectorizerInterface
{
    /**
     * @param array<string, mixed> $queryOptions
     */
    public function __construct(
        private readonly VectorizerInterface $inner,
        private readonly array $queryOptions = [],
    ) {
    }

    public function vectorize(string|\Stringable|DocumentInterface|array $values, array $options = []): Vector|VectorDocumentInterface|array
    {
        return $this->inner->vectorize($values, $this->queryOptions + $options);
    }
}
