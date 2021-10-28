<?php

namespace App\Listeners;

use App\Actions\UpdateAccountStatusAction;
use App\Events\PaymentSuccessfullySubmittedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use SeanKndy\SonarApi\Client;

class ReactivatesAccountOnPaymentListener
{
    /**
     * Account status to set when an account is 'reactivated' after payment.
     * @var int|string  If name given rather than ID, then account status ID will be queried.
     */
    const REACTIVATION_STATUS = 1;

    /**
     * If the account is one of these statuses and the account pays up, reactivate to status above.
     */
    const REACTIVATES_ACCOUNT_STATUSES = [
        'Inactive / Collections',
        'On Hold',
    ];

    private Client $sonarClient;

    private UpdateAccountStatusAction $updateAccountStatusAction;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(
        Client $sonarClient,
        UpdateAccountStatusAction $updateAccountStatusAction
    ) {
        $this->sonarClient = $sonarClient;
        $this->updateAccountStatusAction = $updateAccountStatusAction;
    }

    /**
     * Handle the event.
     *
     * @param  PaymentSuccessfullySubmittedEvent  $event
     * @return void
     */
    public function handle(PaymentSuccessfullySubmittedEvent $event)
    {
        try {
            $delinquentInvoices = $this->sonarClient->invoices()
                ->where('accountId', $event->paymentSubmission->account->id)
                ->where('delinquent', true)
                ->get();

            if ($delinquentInvoices->count() > 0) {
                // still delinquent, do not continue to reactivate
                return;
            }

            $currentAccountStatus = $this->sonarClient
                ->accountStatuses()
                ->whereHas('accounts', fn($search) => $search->where('id', $event->paymentSubmission->account->id))
                ->first();

            if ($currentAccountStatus && \in_array($currentAccountStatus->name, self::REACTIVATES_ACCOUNT_STATUSES)) {
                $newAccountStatus = is_int(self::REACTIVATION_STATUS)
                    ? self::REACTIVATION_STATUS
                    : $this->sonarClient
                        ->accountStatuses()
                        ->where('name', self::REACTIVATION_STATUS)
                        ->first();

                ($this->updateAccountStatusAction)($event->paymentSubmission->account, $newAccountStatus);
            }
        } catch (\Exception $e) {
            //
        }
    }
}
