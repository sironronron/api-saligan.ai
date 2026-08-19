<?php

use App\Ai\Tools\CreateTodoTool;
use App\Ai\Tools\RequestIntakeFormTool;
use App\Support\DraftingIntent;
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

it('request intake form handle echoes the declared document type and the fields it accepted', function () {
    $result = (new RequestIntakeFormTool)->handle(new Request([
        'document_type' => 'special power of attorney',
        'fields' => [['key' => 'principal_name', 'label' => 'Principal', 'type' => 'text', 'required' => true]],
    ]));

    expect(json_decode($result, true))
        ->toHaveKey('document_type', 'special power of attorney')
        ->toHaveKey('accepted', 1)
        ->toHaveKey('fields', [['key' => 'principal_name', 'label' => 'Principal', 'type' => 'text', 'required' => true]])
        // The model is told to stop and wait rather than left to infer it from
        // a bare echo of its own arguments.
        ->and($result)->toContain('[Intake Form Submission]');
});

it('opens no form and says so when nothing usable was passed', function () {
    // A form with no fields is a dead end the user cannot answer, so the tool
    // refuses it and tells the model not to promise one.
    $result = (new RequestIntakeFormTool)->handle(new Request([
        'document_type' => 'complaint',
        'fields' => [['type' => 'text'], 'not-an-object'],
    ]));

    expect(json_decode($result, true))
        ->toHaveKey('accepted', 0)
        ->not->toHaveKey('fields')
        ->and($result)->toContain('No form was shown');
});

it('caps a runaway field list at the number the wizard can reasonably ask for', function () {
    $fields = [];

    for ($i = 0; $i < 40; $i++) {
        $fields[] = ['key' => 'field_'.$i, 'label' => 'Field '.$i, 'type' => 'text', 'required' => false];
    }

    $result = json_decode((new RequestIntakeFormTool)->handle(new Request(['fields' => $fields])), true);

    expect($result['accepted'])->toBe(DraftingIntent::MAX_INTAKE_FIELDS);
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
    // The point is that a null callback is not invoked; the tool still answers.
    $result = (new RequestIntakeFormTool)->handle(new Request([
        'document_type' => 'complaint',
        'fields' => [['key' => 'complainant_name', 'label' => 'Complainant', 'type' => 'text', 'required' => true]],
    ]));

    expect(json_decode($result, true))->toHaveKey('document_type', 'complaint');
});

it('suppressed intake form tool instructs the model to draft from the case context', function () {
    $tool = new RequestIntakeFormTool(suppressed: true);

    $result = $tool->handle(new Request([
        'document_type' => 'formal letter',
        'fields' => [['key' => 'facts', 'label' => 'Facts', 'type' => 'textarea', 'required' => true]],
    ]));

    expect($result)
        ->toContain('INTAKE FORM SUPPRESSED')
        ->toContain('Draft the complete document now using the CASE CONTEXT')
        ->not->toContain('"fields"');
});

it('suppressed intake form tool does not fire the gathering_facts status', function () {
    $statuses = [];

    $tool = new RequestIntakeFormTool(function (string $status, ?string $label = null) use (&$statuses): void {
        $statuses[] = [$status, $label];
    }, suppressed: true);

    $tool->handle(new Request([
        'document_type' => 'formal letter',
        'fields' => [],
    ]));

    expect($statuses)->toBe([]);
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
