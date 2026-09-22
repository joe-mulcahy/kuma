<?php

declare(strict_types=1);

namespace SimpleKuma\Stats;

use mysqli;
use SimpleKuma\Database\ClicksTableResolver;
use SimpleKuma\Tracking\ConversionEventClassifier;
use SimpleKuma\Utils\Formatter;

final class EventBreakdownService
{
    public function __construct(private mysqli $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function get(?int $campaignId, string $dateFrom, string $dateTo, string $timezone): array
    {
        $range = Formatter::convertDateRangeToUTC($dateFrom, $dateTo, $timezone);
        $clicks = ClicksTableResolver::getStatsTable($this->db);
        $classification = ConversionEventClassifier::sqlClassificationExpression('cv.event_key');
        $revenue = CampaignStatsExpressions::classifiedRevenueAggregate('cv');
        $where = ['cl.ts >= ?', 'cl.ts <= ?'];
        $params = [$range['from'], $range['to']];
        $types = 'ss';
        if ($campaignId !== null) {
            $where[] = 'cl.campaign_id = ?';
            $params[] = $campaignId;
            $types .= 'i';
        }

        $sql = "SELECT COALESCE(NULLIF(cv.event_key, ''), 'conversion') event_type,
                       {$classification} event_classification,
                       COUNT(*) event_count,
                       COUNT(DISTINCT cv.click_id) unique_clicks,
                       {$revenue} revenue
                FROM conversions cv
                INNER JOIN `{$clicks}` cl ON cl.click_id = cv.click_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY event_type, event_classification
                ORDER BY MIN(cv.ts), event_type";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'event_type' => (string) $row['event_type'],
                'event_classification' => (string) $row['event_classification'],
                'count' => (int) $row['event_count'],
                'unique_clicks' => (int) $row['unique_clicks'],
                'revenue' => (float) $row['revenue'],
            ];
        }
        $stmt->close();
        return $rows;
    }
}
