<?php

use App\Ai\Tools\CreateTodoTool;
use App\Ai\Tools\RequestIntakeFormTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;

it('resolves the intake form tool name to request_intake_form', function () {
    expect(ToolNameResolver::resolve(new RequestIntakeFormTool))->toBe('request_intake_form');
});

it('resolves the create todo tool name to create_todo', function () {
    expect(ToolNameResolver::resolve(new CreateTodoTool('019fcbbb-5c51-7168-bad0-128742198ebd')))->toBe('create_todo');
});

it('request intake form schema serializes to a valid tool definition', function () {
    $schema = (new RequestIntakeFormTool)->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKey('document_type')
        ->and($schema['document_type']->toArray())
        ->toHaveKey('type', 'string')
        ->and($schema['fields']->toArray())
        ->toHaveKey('type', 'array')
        ->and($schema['fields']->toArray()['items'])
        ->toHaveKey('type', 'object')
        ->and($schema['fields']->toArray()['items']['properties'])
        ->toHaveKeys(['key', 'label', 'type', 'options', 'required']);
});

it('request intake form handle echoes the declared document type', function () {
    $result = (new RequestIntakeFormTool)->handle(new Request([
        'document_type' => 'special power of attorney',
        'fields' => [['key' => 'principal_name', 'label' => 'Principal', 'type' => 'text', 'required' => true]],
    ]));

    expect($result)->toBe(json_encode([
        'document_type' => 'special power of attorney',
        'fields' => [['key' => 'principal_name', 'label' => 'Principal', 'type' => 'text', 'required' => true]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
});

it('request intake form handle tolerates a missing document type', function () {
    $result = (new RequestIntakeFormTool)->handle(new Request([
        'fields' => [['key' => 'facts', 'label' => 'Facts', 'type' => 'textarea', 'required' => true]],
    ]));

    expect(json_decode($result, true))
        ->toHaveKey('document_type', null)
        ->toHaveKey('fields');
});

it('fires the gathering_facts status when the intake form tool runs', function () {
    $statuses = [];

    $tool = new RequestIntakeFormTool(function (string $status, ?string $label = null) use (&$statuses): void {
        $statuses[] = [$status, $label];
    });

    $tool->handle(new Request([
        'document_type' => 'complaint',
        'fields' => [['key' => 'complainant_name', 'label' => 'Complainant', 'type' => 'text', 'required' => true]],
    ]));

    expect($statuses)->toBe([['gathering_facts', null]]);
});

it('does not fire a status when the intake form tool has no callback', function () {
    $result = (new RequestIntakeFormTool)->handle(new Request([
        'document_type' => 'complaint',
    ]));

    expect(json_decode($result, true))->toHaveKey('document_type', 'complaint');
});

it('create todo schema serializes to a valid tool definition', function () {
    $schema = (new CreateTodoTool('019fcbbb-5c51-7168-bad0-128742198ebd'))->schema(new JsonSchemaTypeFactory);

    expect($schema['items']->toArray())
        ->toHaveKey('type', 'array')
        ->and($schema['items']->toArray()['items'])
        ->toHaveKey('type', 'object')
        ->and($schema['items']->toArray()['items']['properties'])
        ->toHaveKeys(['title', 'status', 'priority', 'due_hint']);
});
