<?php

use App\Ai\Tools\CreateTodoTool;
use App\Ai\Tools\RequestIntakeFormTool;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\ToolNameResolver;

it('resolves the intake form tool name to request_intake_form', function () {
    expect(ToolNameResolver::resolve(new RequestIntakeFormTool))->toBe('request_intake_form');
});

it('resolves the create todo tool name to create_todo', function () {
    expect(ToolNameResolver::resolve(new CreateTodoTool('019fcbbb-5c51-7168-bad0-128742198ebd')))->toBe('create_todo');
});

it('request intake form schema serializes to a valid tool definition', function () {
    $schema = (new RequestIntakeFormTool)->schema(new JsonSchemaTypeFactory);

    expect($schema['fields']->toArray())
        ->toHaveKey('type', 'array')
        ->and($schema['fields']->toArray()['items'])
        ->toHaveKey('type', 'object')
        ->and($schema['fields']->toArray()['items']['properties'])
        ->toHaveKeys(['key', 'label', 'type', 'options', 'required']);
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
