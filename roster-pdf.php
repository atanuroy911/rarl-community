<?php
/**
 * RARL — Public Membership Roster PDF
 * Streams a fresh PDF (not stored) listing member ID + name, grouped by
 * country. No login required — same public data shown on directory.php.
 * ?country=X limits to one country; omit for the full roster.
 */
require_once __DIR__ . '/functions.php';

$pdo = db();
$filterCountry = trim($_GET['country'] ?? '');

$sql = "SELECT member_code, full_name, lab_name, type, country FROM members
        WHERE status = 'active' AND directory_visible = 1 AND member_code IS NOT NULL AND member_code != ''";
$params = [];
if ($filterCountry !== '') { $sql .= " AND country = ?"; $params[] = $filterCountry; }
$sql .= " ORDER BY country, (type = 'lab'), full_name, lab_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$byCountry = [];
foreach ($rows as $r) {
    $country = $r['country'] ?: 'Unspecified';
    $byCountry[$country][] = $r;
}
ksort($byCountry);

if (!class_exists('FPDF') && file_exists(__DIR__ . '/libs/fpdf/fpdf.php')) require_once __DIR__ . '/libs/fpdf/fpdf.php';
if (!class_exists('FPDF')) { http_response_code(500); echo 'PDF library unavailable.'; exit; }

[$inkR, $inkG, $inkB] = brandRgb(BRAND_INK);
[$redR, $redG, $redB] = brandRgb(BRAND_RED);

class RosterPDF extends FPDF {
    public string $subtitle = '';
    function Header(): void {
        $this->SetFont('Helvetica', 'B', 14);
        $this->Cell(0, 8, fpdfEnc('Robotics & Automation Research Lab (RARL) — Membership Roster'), 0, 1, 'L');
        if ($this->subtitle !== '') {
            $this->SetFont('Helvetica', '', 9);
            $this->SetTextColor(120, 120, 120);
            $this->Cell(0, 5, fpdfEnc($this->subtitle), 0, 1, 'L');
        }
        $this->Ln(2);
    }
    function Footer(): void {
        $this->SetY(-12);
        $this->SetFont('Helvetica', '', 7);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 8, 'Page ' . $this->PageNo() . ' — Generated ' . date('d M Y'), 0, 0, 'C');
    }
    function tableHeader(): void {
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetFillColor(240, 240, 240);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(40, 7, 'Member ID', 1, 0, 'L', true);
        $this->Cell(0, 7, 'Name', 1, 1, 'L', true);
    }
}

$pdf = new RosterPDF('P', 'mm', 'A4');
$pdf->subtitle = $filterCountry !== '' ? $filterCountry : (count($byCountry) . ' countries, ' . count($rows) . ' members');
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

foreach ($byCountry as $country => $members) {
    if ($pdf->GetY() > 260) { $pdf->AddPage(); }
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetTextColor($redR, $redG, $redB);
    $pdf->Ln(2);
    $pdf->Cell(0, 7, fpdfEnc($country) . ' (' . count($members) . ')', 0, 1, 'L');
    $pdf->tableHeader();

    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(40, 40, 40);
    $fill = false;
    foreach ($members as $m) {
        if ($pdf->GetY() > 275) { $pdf->AddPage(); $pdf->tableHeader(); $pdf->SetFont('Helvetica', '', 9); $pdf->SetTextColor(40, 40, 40); }
        $name = $m['type'] === 'lab' ? $m['lab_name'] : $m['full_name'];
        $pdf->SetFillColor(250, 250, 250);
        $pdf->Cell(40, 6.5, '#' . $m['member_code'], 1, 0, 'L', $fill);
        $pdf->Cell(0, 6.5, fpdfEnc($name), 1, 1, 'L', $fill);
        $fill = !$fill;
    }
}

$filename = $filterCountry !== '' ? 'RARL-Roster-' . preg_replace('/[^A-Za-z0-9]+/', '-', $filterCountry) . '.pdf' : 'RARL-Membership-Roster.pdf';
$pdf->Output('D', $filename);
