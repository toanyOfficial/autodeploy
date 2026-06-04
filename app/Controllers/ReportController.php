<?php

namespace App\Controllers;

use App\Core\Response;
use App\Services\ReportService;

final class ReportController
{
    public function show(int $historyId): void
    {
        $report = (new ReportService())->readByHistoryId($historyId);
        if ($report === null) {
            Response::json(['message' => 'Report not found.'], 404);
            return;
        }

        Response::view('reports/show', ['report' => $report]);
    }
}
