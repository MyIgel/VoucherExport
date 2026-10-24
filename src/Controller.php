<?php

declare(strict_types=1);

namespace Engelsystem\VoucherExport;

use Carbon\CarbonInterval;
use Engelsystem\Controllers\BaseController;
use Engelsystem\Helpers\Authenticator;
use Engelsystem\Helpers\Carbon;
use Engelsystem\Helpers\Goodie;
use Engelsystem\Http\Request;
use Engelsystem\Http\Response;
use Engelsystem\Models\User\User;

class Controller extends BaseController
{
    protected array $permissions = [
        'admin_groups',
        'config.edit',
        'plugin.edit',
    ];

    public function __construct(
        protected Authenticator $auth,
        protected Response $response,
    ) {
    }

    public function index(): Response
    {
        return $this->response->withView('voucher-export/index');
    }

    public function export(Request $request): Response
    {
        $users = User::query()
            ->leftJoin('users_settings', 'users_settings.user_id', 'users.id')
            ->where('email_goodie', true)
            ->with(['state', 'settings', 'worklogs'])
            ->get();

        $data = [];
        foreach ($users as $user) {
            $shiftEntries = $user->shiftEntries()->with(['shift', 'freeloadedBy'])->get();
            $hours = $user->worklogs->sum('hours');
            foreach ($shiftEntries as $entry) {
                if ($entry->freeloaded_by) {
                    continue;
                }
                $shift = $entry->shift;
                $hours += round($shift->end->timestamp - $shift->start->timestamp) / 60 / 60;
            }

            $entry = [
                'id' => $user->id,
                'nick' => $user->name,
                'email' => $user->email,
                'email_human' => (int)$user->settings->email_human,
                'hours' => Carbon::formatDuration(CarbonInterval::minutes(
                    round($hours * 60)
                ), '%dh %02dm'),
                'goodie_score' => Carbon::formatDuration(CarbonInterval::minutes(
                    round(Goodie::userScore($user) * 60)
                ), '%dh %02dm'),
                'force_active' => (int)$user->state->force_active,
                'force_food' => (int)$user->state->force_food,
                'arrived' => (int)$user->state->arrived,
                'info' => $user->state->user_info,
            ];

            $entry['probably_eligible'] = $entry['arrived'];
            if($entry['info']) {
                $entry['probably_eligible'] = '?';
            }
            if($entry['force_active']) {
                $entry['probably_eligible'] = 1;
            }

            $data[] = $entry;
        }

        $name = 'voucher-export_' . Carbon::now()->format('Ymd-His') . '.csv';
        return $this->response
            ->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->withContent($this->toCsv($data));
    }

    protected function toCsv(array $data): string
    {
        if (empty($data)) {
            return '';
        }

        $csv = fopen('php://memory', 'r+');
        $data = array_merge([array_keys($data[0])], $data);
        foreach ($data as $row) {
            fputcsv($csv, $row, escape: '');
        }

        rewind($csv);
        return stream_get_contents($csv);
    }
}
