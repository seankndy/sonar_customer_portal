<?php

namespace App\Http\Controllers;

use App\Actions\CreateAccountTicketAction;
use App\Actions\CreateTicketReplyAction;
use App\Actions\UpdateTicketStatusAction;
use App\DataTransferObjects\AccountTicketData;
use App\DataTransferObjects\TicketReplyData;
use App\Http\Requests\TicketReplyRequest;
use App\Http\Requests\TicketRequest;
use SeanKndy\SonarApi\Client;
use SeanKndy\SonarApi\Resources\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class TicketController extends Controller
{
    private Client $sonarClient;

    public function __construct(Client $sonarClient)
    {
        $this->sonarClient = $sonarClient;
    }

    public function index(Request $request): View
    {
        $request->validate([
            'status' => 'in:OPEN,CLOSED',
        ]);

        $status = $request->has('status')
            ? $request->input('status')
            : $request->session()->get('status', 'OPEN');
        $request->session()->put('status', $status);

        $tickets = $this->sonarClient
            ->tickets()
            ->with(['ticketReplies' => fn($query) => $query->sortBy('createdAt', 'DESC')])
            ->where('ticketableId', [
                session()->get('account')->id,
                ...\array_map(fn($a) => $a->id, session()->get('account')->childAccounts),
            ])
            ->where('ticketableType', 'Account')
            ->where('inboundMailboxId', '!=', null)
            ->where('status', $status === 'OPEN' ? '!=' : '=', $status === 'OPEN' ? 'CLOSED' : $status)
            ->sortBy('updatedAt', 'DESC')
            ->paginate(5, $request->input('page', 1), '/'.$request->path());

        $ticketAccounts = $this->associateTicketsToAccounts($tickets);

        return view("pages.tickets.index", [
            'tickets' => $tickets,
            'ticketAccounts' => $ticketAccounts,
            'status' => $status,
        ]);
    }

    /**
     * Show an individual ticket
     * @return \Illuminate\Http\RedirectResponse|View
     */
    public function show(int $id)
    {
        try {
            $ticket = $this->getTicket($id);
        } catch (\Exception $e) {
            return redirect()->action("TicketController@index")
                ->withErrors(utrans("errors.invalidTicketID"));
        }

        $ticketAccounts = $this->associateTicketsToAccounts([$ticket]);

        if ($ticket) {
            return view("pages.tickets.show", [
                'ticket' => $ticket,
                'account' => $ticketAccounts[$id],
            ]);
        }

        return redirect()->action("TicketController@index")
            ->withErrors(utrans("errors.invalidTicketID"));
    }

    /**
     * Show ticket creation page
     * @return \Illuminate\Http\RedirectResponse|View
     */
    public function create()
    {
        if (!get_user()->emailAddress) {
            return redirect()->action("ProfileController@show")
                ->withErrors(utrans("errors.mustSetEmailAddress"));
        }

        return view('pages.tickets.create', [
            'accounts' => [
                session()->get('account'),
                ...session()->get('account')->childAccounts
            ]
        ]);
    }

    /**
     * Create a new ticket
     * @return $this|\Illuminate\Http\RedirectResponse
     */
    public function store(
        TicketRequest $request,
        CreateAccountTicketAction $createAccountTicketAction,
        CreateTicketReplyAction $createTicketReplyAction
    ) {
        try {
            $ticket = $createAccountTicketAction(AccountTicketData::fromRequest($request));

            $createTicketReplyAction(new TicketReplyData([
                'ticket' => $ticket,
                'body' => $request->input('description'),
                'author' => get_user()->name,
                'authorEmail' => get_user()->emailAddress,
            ]));

            return redirect()->action("TicketController@index")->with('success', utrans("tickets.ticketCreated"));
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return redirect()->back()->withErrors(utrans("errors.failedToCreateTicket"))->withInput();
        }
    }

    /**
     * Post a reply to a ticket
     * @param TicketReplyRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function postReply(
        int $ticketId,
        TicketReplyRequest $request,
        CreateTicketReplyAction $createTicketReplyAction
    ) {
        try {
            $ticket = $this->getTicket($ticketId);
        } catch (\Exception $e) {
            return redirect()->action("TicketController@index")
                ->withErrors(utrans("errors.invalidTicketID"));
        }

        try {
            $createTicketReplyAction(new TicketReplyData([
                'ticket' => $ticket,
                'body' => $request->input('reply'),
                'author' => get_user()->name,
                'authorEmail' => get_user()->emailAddress,
            ]));

            return redirect()->back()->with('success', utrans("tickets.replyPosted"));
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(utrans("errors.failedToPostReply"));
        }
    }

    public function reopen(
        int $ticketId,
        UpdateTicketStatusAction $updateTicketStatusAction,
        CreateTicketReplyAction $createTicketReplyAction
    ): RedirectResponse {
        try{
            if ($ticket = $this->getTicket($ticketId)) {
                $updateTicketStatusAction($ticket, 'OPEN');

                $createTicketReplyAction(new TicketReplyData([
                    'ticket' => $ticket,
                    'body' => '[Automated response] Reopening ticket from customer portal.',
                    'author' => get_user()->name,
                    'authorEmail' => get_user()->emailAddress,
                ]));

                return redirect()->back()->with('success', utrans("tickets.ticketUpdated"));
            }
        } catch (\Exception $e) {
            //
        }

        return redirect()->back()->withErrors(utrans("errors.failedToUpdateTicket"));
    }

    public function close(
        int $ticketId,
        UpdateTicketStatusAction $updateTicketStatusAction,
        CreateTicketReplyAction $createTicketReplyAction
    ): RedirectResponse {
        try {
            if ($ticket = $this->getTicket($ticketId)) {
                $updateTicketStatusAction($ticket, 'CLOSED');

                $createTicketReplyAction(new TicketReplyData([
                    'ticket' => $ticket,
                    'body' => '[Automated response] Closing ticket from customer portal.',
                    'author' => get_user()->name,
                    'authorEmail' => get_user()->emailAddress,
                ]));

                return redirect()->back()->with('success', utrans("tickets.ticketUpdated"));
            }
        } catch (\Exception $e) {
            //
        }

        return redirect()->back()->withErrors(utrans("errors.failedToUpdateTicket"));
    }

    private function getTicket(int $id): ?Ticket
    {
        return $this->sonarClient
            ->tickets()
            ->with(['ticketReplies' => fn($query) => $query->sortBy('createdAt', 'ASC')])
            ->where('id', $id)
            ->where('ticketableId', [
                session()->get('account')->id,
                ...\array_map(fn($a) => $a->id, session()->get('account')->childAccounts)
            ])
            ->where('ticketableType', 'Account')
            ->where('inboundMailboxId', '!=', null)
            ->first();
    }

    /**
     * @param \SeanKndy\SonarApi\Resources\Ticket[] $tickets
     * @return array
     */
    private function associateTicketsToAccounts(iterable $tickets): array
    {
        $accounts = collect([
            session()->get('account'),
            ...session()->get('account')->childAccounts
        ])->keyBy(fn($account) => $account->id);

        // associate ticket to account
        $ticketAccounts = [];
        foreach ($tickets as $ticket) {
            $ticketAccounts[$ticket->id] = $accounts[$ticket->ticketableId];
        }

        return $ticketAccounts;
    }
}
