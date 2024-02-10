<?php

namespace Flarum\Api\Endpoint;

use Closure;
use Flarum\Api\Endpoint\Concerns\ExtractsListingParams;
use Flarum\Api\Endpoint\Concerns\HasAuthorization;
use Flarum\Api\Endpoint\Concerns\HasCustomRoute;
use Flarum\Api\Endpoint\Concerns\HasEagerLoading;
use Flarum\Api\Endpoint\Concerns\HasHooks;
use Flarum\Http\RequestUtil;
use Flarum\Search\SearchCriteria;
use Flarum\Search\SearchManager;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Psr\Http\Message\ResponseInterface as Response;
use RuntimeException;
use Tobyz\JsonApiServer\Context;
use Tobyz\JsonApiServer\Endpoint\Concerns\IncludesData;
use Tobyz\JsonApiServer\Endpoint\Index as BaseIndex;
use Tobyz\JsonApiServer\Exception\BadRequestException;
use Tobyz\JsonApiServer\Exception\ForbiddenException;
use Tobyz\JsonApiServer\Exception\Sourceable;
use Tobyz\JsonApiServer\Pagination\OffsetPagination;
use Tobyz\JsonApiServer\Resource\Countable;
use Tobyz\JsonApiServer\Resource\Listable;
use Tobyz\JsonApiServer\Serializer;
use function Tobyz\JsonApiServer\apply_filters;
use function Tobyz\JsonApiServer\json_api_response;
use function Tobyz\JsonApiServer\parse_sort_string;

class Index extends BaseIndex implements Endpoint
{
    use HasAuthorization;
    use IncludesData;
    use HasEagerLoading;
    use HasCustomRoute;
    use ExtractsListingParams;
    use HasHooks;

    public function paginate(int $defaultLimit = 20, int $maxLimit = 50): static
    {
        $this->limit = $defaultLimit;
        $this->maxLimit = $maxLimit;

        $this->paginationResolver = fn(Context $context) => new OffsetPagination(
            $context,
            $defaultLimit,
            $maxLimit,
        );

        return $this;
    }

    public function execute(Context $context): mixed
    {
        return null;
    }

    /** {@inheritDoc} */
    public function handle(Context $context): ?Response
    {
        $collection = $context->collection;

        if (!$collection instanceof Listable) {
            throw new RuntimeException(
                sprintf('%s must implement %s', get_class($collection), Listable::class),
            );
        }

        if (!$this->isVisible($context)) {
            throw new ForbiddenException();
        }

        $this->callBeforeHook($context);

        $pagination = ($this->paginationResolver)($context);

        $query = $collection->query($context);

        // This model has a searcher API, so we'll use that instead of the default.
        // The searcher API allows swapping the default search engine for a custom one.
        $search = resolve(SearchManager::class);
        $modelClass = $query->getModel()::class;

        if ($query instanceof Builder && $search->searchable($modelClass)) {
            $actor = RequestUtil::getActor($context->request);

            $extracts = $this->defaultExtracts($context);

            $filters = $this->extractFilterValue($context, $extracts);
            $sort = $this->extractSortValue($context, $extracts);
            $limit = $this->extractLimitValue($context, $extracts);
            $offset = $this->extractOffsetValue($context, $extracts);

            $sortIsDefault = ! $context->queryParam('sort');

            // @todo: resources and endpoints have no room for dependency injection
            $results = resolve(SearchManager::class)->query(
                $modelClass,
                new SearchCriteria($actor, $filters, $limit, $offset, $sort, $sortIsDefault),
            );

            $context = $context->withSearchResults($results);
        }
        // If the model doesn't have a searcher API, we'll just use the default logic.
        else {
            $context = $context->withQuery($query);

            $this->applySorts($query, $context);
            $this->applyFilters($query, $context);

            $pagination?->apply($query);
        }

        $meta = $this->serializeMeta($context);
        $links = [];

        if (
            $collection instanceof Countable &&
            !is_null($total = $collection->count($query, $context))
        ) {
            $meta['page']['total'] = $total;
        }

        $models = $collection->results($query, $context);

        ['models' => $models] = $this->callAfterHook($context, compact('models'));

        $this->loadRelations(Collection::make($models), $context->request);

        $serializer = new Serializer($context);

        $include = $this->getInclude($context);

        foreach ($models as $model) {
            $serializer->addPrimary(
                $context->resource($collection->resource($model, $context)),
                $model,
                $include,
            );
        }

        [$data, $included] = $serializer->serialize();

        if ($pagination) {
            $meta['page'] = array_merge($meta['page'] ?? [], $pagination->meta());
            $links = array_merge($links, $pagination->links(count($data), $total ?? null));
        }

        return json_api_response(compact('data', 'included', 'meta', 'links'));
    }

    private function applySorts($query, Context $context): void
    {
        if (!($sortString = $context->queryParam('sort', $this->defaultSort))) {
            return;
        }

        $sorts = $context->collection->sorts();

        foreach (parse_sort_string($sortString) as [$name, $direction]) {
            foreach ($sorts as $field) {
                if ($field->name === $name && $field->isVisible($context)) {
                    $field->apply($query, $direction, $context);
                    continue 2;
                }
            }

            throw (new BadRequestException("Invalid sort: $name"))->setSource([
                'parameter' => 'sort',
            ]);
        }
    }

    private function applyFilters($query, Context $context): void
    {
        if (!($filters = $context->queryParam('filter'))) {
            return;
        }

        if (!is_array($filters)) {
            throw (new BadRequestException('filter must be an array'))->setSource([
                'parameter' => 'filter',
            ]);
        }

        try {
            apply_filters($query, $filters, $context->collection, $context);
        } catch (Sourceable $e) {
            throw $e->prependSource(['parameter' => 'filter']);
        }
    }

    public function route(): EndpointRoute
    {
        return new EndpointRoute(
            name: 'index',
            path: $this->path ?? '/',
            method: 'GET',
        );
    }
}