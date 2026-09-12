<?php

use App\Models\Account;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\FiscalCoverageEvidence;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentAction;
use App\Models\FiscalSyncCursor;
use App\Models\FiscalSyncSubscription;
use App\Support\CurrentAccount;

function seedFiscalAccountStack(Account $account): array
{
    $client = Client::factory()->create(['account_id' => $account->id]);
    $credential = ClientCredential::factory()->create(['client_id' => $client->id]);
    $subscription = FiscalSyncSubscription::factory()->create(['client_id' => $client->id]);
    $cursor = FiscalSyncCursor::factory()->create(['client_id' => $client->id]);
    $document = FiscalDocument::factory()->create(['client_id' => $client->id]);
    $evidence = FiscalCoverageEvidence::factory()->create(['client_id' => $client->id]);
    $action = FiscalDocumentAction::factory()->create(['fiscal_document_id' => $document->id]);

    return compact('client', 'credential', 'subscription', 'cursor', 'document', 'evidence', 'action');
}

it('isolates fiscal rows between accounts via the account scope', function () {
    $accountA = Account::factory()->create(['profile' => 'B']);
    $accountB = Account::factory()->create(['profile' => 'B']);

    CurrentAccount::clear();
    $stackA = seedFiscalAccountStack($accountA);
    $stackB = seedFiscalAccountStack($accountB);

    CurrentAccount::set($accountA);

    expect(ClientCredential::count())->toBe(1)
        ->and(ClientCredential::first()->id)->toBe($stackA['credential']->id)
        ->and(FiscalSyncSubscription::count())->toBe(1)
        ->and(FiscalSyncSubscription::first()->id)->toBe($stackA['subscription']->id)
        ->and(FiscalSyncCursor::count())->toBe(1)
        ->and(FiscalSyncCursor::first()->id)->toBe($stackA['cursor']->id)
        ->and(FiscalDocument::count())->toBe(1)
        ->and(FiscalDocument::first()->id)->toBe($stackA['document']->id)
        ->and(FiscalCoverageEvidence::count())->toBe(1)
        ->and(FiscalCoverageEvidence::first()->id)->toBe($stackA['evidence']->id)
        ->and(FiscalDocumentAction::count())->toBe(1)
        ->and(FiscalDocumentAction::first()->id)->toBe($stackA['action']->id);

    // The filter is the scope, not missing data: without scopes both stacks exist.
    expect(ClientCredential::withoutGlobalScopes()->count())->toBe(2)
        ->and(FiscalSyncSubscription::withoutGlobalScopes()->count())->toBe(2)
        ->and(FiscalSyncCursor::withoutGlobalScopes()->count())->toBe(2)
        ->and(FiscalDocument::withoutGlobalScopes()->count())->toBe(2)
        ->and(FiscalCoverageEvidence::withoutGlobalScopes()->count())->toBe(2)
        ->and(FiscalDocumentAction::withoutGlobalScopes()->count())->toBe(2);

    // Sanity: stack B really belongs to the other account.
    expect($stackB['client']->account_id)->toBe($accountB->id);

    CurrentAccount::clear();
});
