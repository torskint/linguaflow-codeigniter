<?php

namespace Torskint\LinguaFlowCi;

use Torskint\LinguaFlowCi\Archive\ArchiveBuilder;
use Torskint\LinguaFlowCi\Config\LinguaFlow;
use Torskint\LinguaFlowCi\Delta\DeltaDetector;
use Torskint\LinguaFlowCi\Http\CurlHubClient;
use Torskint\LinguaFlowCi\Http\HubClient;
use Torskint\LinguaFlowCi\Merge\ResultMerger;
use Torskint\LinguaFlowCi\Source\LanguageSource;
use Torskint\LinguaFlowCi\Store\JobStore;
use Torskint\LinguaFlowCi\Store\SqliteJobStore;
use Torskint\LinguaFlowCi\Support\FileWriter;
use Torskint\LinguaFlowCi\Sync\Sender;
use Torskint\LinguaFlowCi\Sync\Syncer;
use Torskint\LinguaFlowCi\Webhook\WebhookVerifier;

/**
 * Assemble les objets du cycle d'envoi à partir des réglages (commandes spark et contrôleur du webhook passent par ici).
 */
class Hub
{
	// Constructeur : réglages.
	public function __construct(private LinguaFlow $config) {}

	/**
	 * Réglages de l'application (app/Config/LinguaFlow.php s'il existe, sinon ceux du package et du .env).
	 */
	public static function make(): self
	{
		return new self(config('LinguaFlow') ?? new LinguaFlow);
	}

	/**
	 * Réglages.
	 */
	public function config(): LinguaFlow
	{
		return $this->config;
	}

	/**
	 * Vrai si l'adresse du hub et la clé d'API sont renseignées.
	 */
	public function configured(): bool
	{
		return $this->config->hubUrl !== '' && $this->config->apiKey !== '';
	}

	/**
	 * Dossier de travail (base, boîte d'envoi, sauvegardes).
	 */
	public function workPath(): string
	{
		return rtrim($this->config->workPath !== '' ? $this->config->workPath : WRITEPATH.'linguaflow', '/\\');
	}

	/**
	 * Client du hub.
	 */
	public function client(): HubClient
	{
		return new CurlHubClient($this->config->hubUrl, $this->config->apiKey);
	}

	/**
	 * Suivi local des envois.
	 */
	public function store(): JobStore
	{
		return new SqliteJobStore($this->workPath().'/jobs.sqlite');
	}

	/**
	 * Fichiers de langue de l'application.
	 */
	public function language(): LanguageSource
	{
		return new LanguageSource(
			$this->config->langPath !== '' ? $this->config->langPath : APPPATH.'Language',
			trim($this->config->archivePrefix, '/'),
			$this->config->sourceLocale,
			new FileWriter($this->workPath().'/backups'),
		);
	}

	/**
	 * Adresse publique du webhook, à donner au hub.
	 */
	public function webhookUrl(): string
	{
		return $this->config->webhookUrl !== '' ? $this->config->webhookUrl : site_url('linguaflow/webhook');
	}

	/**
	 * Émetteur des envois.
	 */
	public function sender(?HubClient $client = null, ?JobStore $store = null): Sender
	{
		$language = $this->language();
		$host = parse_url(site_url(), PHP_URL_HOST);

		return new Sender(new DeltaDetector($language, $this->config->sourceLocale), new ArchiveBuilder($language), $client ?? $this->client(), $store ?? $this->store(), [
			'client_ref' => $this->config->clientRef !== '' ? $this->config->clientRef : ($host ?: null),
			'delivery' => $this->config->delivery === 'webhook' ? 'webhook' : 'local',
			'webhook_url' => $this->config->delivery === 'webhook' ? $this->webhookUrl() : null,
			'source' => $this->config->sourceLocale,
			'locales' => $this->config->locales(),
			'outbox' => $this->workPath().'/outbox',
			'interval' => $this->config->pollInterval,
		]);
	}

	/**
	 * Synchroniseur des envois.
	 */
	public function syncer(): Syncer
	{
		$client = $this->client();
		$store = $this->store();

		return new Syncer($client, $store, new ResultMerger($this->language()), $this->sender($client, $store), [
			'interval' => $this->config->pollInterval,
			'max_interval' => $this->config->maxInterval,
			'max_age_days' => $this->config->maxAgeDays,
		]);
	}

	/**
	 * Contrôleur de signature du webhook.
	 */
	public function verifier(): WebhookVerifier
	{
		return new WebhookVerifier($this->config->webhookSecret);
	}
}
