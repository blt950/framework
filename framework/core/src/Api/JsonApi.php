<?php

namespace Flarum\Api;

use Flarum\Api\Endpoint\Endpoint;
use Flarum\Api\Endpoint\EndpointRoute;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\Uri;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Tobyz\JsonApiServer\Exception\BadRequestException;
use Tobyz\JsonApiServer\JsonApi as BaseJsonApi;
use Tobyz\JsonApiServer\Resource\Collection;

class JsonApi extends BaseJsonApi
{
    protected string $resourceClass;
    protected string $endpoint;

    public function forResource(string $resourceClass): self
    {
        $this->resourceClass = $resourceClass;

        return $this;
    }

    public function forEndpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    protected function makeContext(Request $request): Context
    {
        if (! $this->endpoint || ! $this->resourceClass || ! class_exists($this->resourceClass)) {
            throw new BadRequestException('No resource or endpoint specified');
        }

        $collection = $this->getCollection((new $this->resourceClass)->type());

        return (new Context($this, $request))
            ->withCollection($collection)
            ->withEndpoint($this->findEndpoint($collection));
    }

    protected function findEndpoint(?Collection $collection): Endpoint
    {
        /** @var \Flarum\Api\Endpoint\Endpoint $endpoint */
        foreach ($collection->endpoints() as $endpoint) {
            if ($endpoint::class === $this->endpoint) {
                return $endpoint;
            }
        }

        throw new BadRequestException('Invalid endpoint specified');
    }

    public function handle(Request $request): Response
    {
        $context = $this->makeContext($request);

        return $context->endpoint->handle($context);
    }

    public function execute(ServerRequestInterface|array $request, array $internal = []): mixed
    {
        /** @var EndpointRoute $route */
        $route = (new $this->endpoint)->route();

        if (is_array($request)) {
            $request = ServerRequestFactory::fromGlobals()->withParsedBody($request);
        }

        $request = $request
            ->withMethod($route->method)
            ->withUri(new Uri($route->path))
            ->withParsedBody([
                'data' => [
                    ...($request->getParsedBody()['data'] ?? []),
                    'type' => (new $this->resourceClass)->type(),
                ],
            ]);

        $context = $this->makeContext($request)
            ->withModelId($data['id'] ?? null);

        foreach ($internal as $key => $value) {
            $context = $context->withInternal($key, $value);
        }

        return $context->endpoint->execute($context);
    }

    public function validateQueryParameters(Request $request): void
    {
        foreach ($request->getQueryParams() as $key => $value) {
            if (
                !preg_match('/[^a-z]/', $key) &&
                !in_array($key, ['include', 'fields', 'filter', 'page', 'sort'])
            ) {
                throw (new BadRequestException("Invalid query parameter: $key"))->setSource([
                    'parameter' => $key,
                ]);
            }
        }
    }

    public function typeForModel(string $modelClass): ?string
    {
        foreach ($this->resources as $resource) {
            if ($resource instanceof AbstractDatabaseResource && $resource->model() === $modelClass) {
                return $resource->type();
            }
        }

        return null;
    }

    public function typesForModels(array $modelClasses): array
    {
        return array_values(array_unique(array_map(fn ($modelClass) => $this->typeForModel($modelClass), $modelClasses)));
    }
}