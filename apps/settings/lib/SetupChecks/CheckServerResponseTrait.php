<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Settings\SetupChecks;

use Generator;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Common trait for setup checks that need to use requests to the same server and check the response
 */
trait CheckServerResponseTrait {
	protected IConfig $config;
	protected IURLGenerator $urlGenerator;
	protected IClientService $clientService;
	protected IL10N $l10n;
	protected LoggerInterface $logger;

	/**
	 * Common helper string in case a check could not fetch any results
	 */
	protected function serverConfigHelp(): string {
		return $this->l10n->t('To allow this check to run you have to make sure that your Web server can connect to itself. Therefore it must be able to resolve and connect to at least one of its `trusted_domains` or the `overwrite.cli.url`. This failure may be the result of a server-side DNS mismatch or outbound firewall rule.');
	}

	/**
	 * Get all possible URLs that need to be checked for a local request test.
	 * This takes all `trusted_domains` and the CLI overwrite URL into account.
	 *
	 * @param string $url The relative URL to test
	 * @return string[] List of possible absolute URLs
	 */
	protected function getTestUrls(string $url): array {
		$hosts = $this->config->getSystemValue('trusted_domains', []);
		$cliUrl = $this->config->getSystemValue('overwrite.cli.url', '');
		if ($cliUrl !== '') {
			$hosts[] = $cliUrl;
		}

		$testUrls = array_merge(
			[$this->urlGenerator->getAbsoluteURL($url)],
			array_map(fn (string $host): string => $host . $url, $hosts),
		);

		return $testUrls;
	}

	/**
	 * Run a HTTP request to check header
	 * @param string $method The HTTP method to use
	 * @param string $url The relative URL to check
	 * @param array{ignoreSSL?: bool, httpErrors?: bool, options?: array} $options Additional options, like
	 *                                                                             [
	 *                                                                             // Ignore invalid SSL certificates (e.g. self signed)
	 *                                                                             'ignoreSSL' => true,
	 *                                                                             // Ignore requests with HTTP errors (will not yield if request has a 4xx or 5xx response)
	 *                                                                             'httpErrors' => true,
	 *                                                                             ]
	 *
	 * @return Generator<int, IResponse>
	 */
	protected function runRequest(string $method, string $url, array $options = []): Generator {
		$options = array_merge(['ignoreSSL' => true, 'httpErrors' => true], $options);

		$client = $this->clientService->newClient();
		$requestOptions = $this->getRequestOptions($options['ignoreSSL'], $options['httpErrors']);
		$requestOptions = array_merge($requestOptions, $options['options'] ?? []);

		foreach ($this->getTestUrls($url) as $testURL) {
			try {
				yield $client->request($method, $testURL, $requestOptions);
			} catch (\Throwable $e) {
				$this->logger->debug('Can not connect to local server for running setup checks', ['exception' => $e, 'url' => $testURL]);
			}
		}
	}

	/**
	 * Run a HEAD request to check header
	 * @param string $url The relative URL to check
	 * @param bool $ignoreSSL Ignore SSL certificates
	 * @param bool $httpErrors Ignore requests with HTTP errors (will not yield if request has a 4xx or 5xx response)
	 * @return Generator<int, IResponse>
	 */
	protected function runHEAD(string $url, bool $ignoreSSL = true, bool $httpErrors = true): Generator {
		return $this->runRequest('HEAD', $url, ['ignoreSSL' => $ignoreSSL, 'httpErrors' => $httpErrors]);
	}

	protected function getRequestOptions(bool $ignoreSSL, bool $httpErrors): array {
		$requestOptions = [
			'connect_timeout' => 10,
			'http_errors' => $httpErrors,
			'nextcloud' => [
				'allow_local_address' => true,
			],
		];
		if ($ignoreSSL) {
			$requestOptions['verify'] = false;
		}
		return $requestOptions;
	}
}
