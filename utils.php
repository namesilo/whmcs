<?php

/**
 * Parse a -margin= argument value into fixed and percentage components.
 *
 * Accepted formats:
 *   FIXED              e.g. 5.10         → fixed=$5.10, pct=0%
 *   PERCENTp/%         e.g. 15p, 22%     → fixed=$0.00, pct=15%
 *   FIXED+PERCENTp/%   e.g. 0.1+10p     → fixed=$0.10, pct=10%
 *   PERCENTp/%+FIXED     e.g. 10p+0.1     → fixed=$0.10, pct=10%  (order-independent)
 *
 * Parts are separated by '+'. Each part ending in p or % is a percentage;
 * a plain number is a fixed amount. Order of parts does not matter.
 *
 * Application formula: finalPrice = base + base * (pct / 100) + fixed
 *
 * @param  string $raw  Raw value after the '=' (e.g. "0.1+10p", "15%", "5.00").
 * @return array{fixed: float, pct: float}
 */
function parseMargin(string $raw): array
{
	$fixed = 0.0;
	$pct   = 0.0;

	foreach (explode('+', $raw) as $part) {
		$part = trim($part);
		if ($part === '') {
			continue;
		}
		// percentage: ending with either % or p
		if (preg_match('/[%p]$/i', $part)) {
			$pct += (float) preg_replace('/[%p]$/i', '', $part);
		} else {
			$fixed += (float) $part;
		}
	}

	return ['fixed' => $fixed, 'pct' => $pct];
}
