<?php

// Verdict of the smoke check of preprod (app:smoke_check in default.php). Separate from
// default.php, so that it can be tested without Deployer (tests/smoke_check.php).

namespace Deployer;

/**
 * null = the release passes, otherwise why it fails: 'curl' (no HTTP answer at all - connection
 * refused, timeout, empty reply ...) or 'status' (an answer other than 2xx, 3xx, 401, 403).
 *
 * 401/403 pass: a project whose homepage needs a login or is behind an IP allowlist still proves
 * that the release boots. A 404 does not - the front controller answers it for a page it does not
 * know, but also nginx when release/www is missing; a project whose `/` is a 404 points
 * smoke_check_path at a public page.
 */
function smokeCheckFailure(string $httpCode, string $curlExit): ?string
{
	if ($curlExit !== '0') {
		return 'curl';
	}
	if (!ctype_digit($httpCode)) {
		return 'status';
	}
	$code = (int) $httpCode;
	return ($code >= 200 && $code < 400) || $code === 401 || $code === 403 ? null : 'status';
}

/**
 * Host header of the smoke check. PROJECT_URL may list several domains separated by commas
 * (adt/make: the TLS certificate gets them all, CN = the first) - sent whole as Host, a
 * multi-tenant front controller picking the tenant by domain answers 404 for the unknown host.
 */
function smokeCheckHost(string $projectUrl): string
{
	return trim(explode(',', $projectUrl)[0]);
}
