<?php

namespace Torskint\LinguaFlowCi\Tests;

use PHPUnit\Framework\TestCase;
use Torskint\LinguaFlowCi\Store\SqliteJobStore;
use Torskint\LinguaFlowCi\Support\LangFileParser;
use Torskint\LinguaFlowCi\Sync\Sender;
use Torskint\LinguaFlowCi\Sync\Syncer;
use Torskint\LinguaFlowCi\Tests\Support\AppFixture;
use Torskint\LinguaFlowCi\Tests\Support\FakeHub;
use Torskint\LinguaFlowCi\Tests\Support\MemoryJobStore;
use ZipArchive;

/**
 * Cycle complet du package sur une application de test : delta, archive, envoi, synchronisation, fusion sans écrasement.
 */
class LanguageFlowTest extends TestCase
{
	use AppFixture;

	/** Fichier secondaire de la langue source. */
	private const USERS = 'app/Language/fr/Admin/Users.php';

	/**
	 * Nettoie le dossier temporaire après chaque test.
	 */
	protected function tearDown(): void
	{
		$this->cleanApp();
	}

	/**
	 * Les fichiers de la langue source sont trouvés, avec les clés pointées ; la clé qui contient un point est signalée à part.
	 */
	public function test_source_files_and_dotted_keys(): void
	{
		$language = $this->makeApp();

		$this->assertSame([self::USERS, $this->file], $language->archivePaths());
		$this->assertSame(['welcome.title' => 'Bienvenue', 'welcome.hello' => 'Bonjour {name}', 'logout' => 'Déconnexion'], $language->entries($this->file, 'fr'));
		$this->assertSame(['app/Language/fr/App.php : with.dot'], $language->skipped());
	}

	/**
	 * Le delta ne demande que les clés absentes ou vides de la langue cible.
	 */
	public function test_delta_lists_only_missing_keys(): void
	{
		$language = $this->makeApp();
		mkdir($this->root.'/app/Language/de', 0775, true);
		file_put_contents($this->root.'/app/Language/de/App.php', "<?php\n\nreturn [\n\t'welcome' => ['title' => 'Willkommen', 'hello' => ''],\n];\n");

		$delta = $this->detector($language)->detect(['fr', 'de']);

		$this->assertSame(['de' => [self::USERS => ['list'], $this->file => ['welcome.hello', 'logout']]], $delta->targets());
		$this->assertSame(3, $delta->count());
	}

	/**
	 * L'archive porte le manifeste CodeIgniter et le fichier source réduit aux clés demandées, relisible sans exécution.
	 */
	public function test_archive_has_codeigniter_manifest_and_trimmed_file(): void
	{
		$language = $this->makeApp();
		$delta = $this->detector($language)->detect(['de']);
		$delta->items = array_values(array_filter($delta->items, fn ($i) => $i['file'] === $this->file));
		$delta->items[0]['keys'] = ['welcome.hello'];

		$built = $this->archive($language)->build($delta, 'fr', ['client_ref' => 'app-1', 'delivery' => 'local', 'webhook_url' => null, 'full' => false]);
		$zip = new ZipArchive;
		$zip->open($built['path']);
		$manifest = json_decode($zip->getFromName('.linguaflow/manifest.json'), true);
		$php = $zip->getFromName($this->file);
		$zip->close();

		$this->assertSame('codeigniter', $manifest['framework']);
		$this->assertSame('fr', $manifest['source_locale']);
		$this->assertSame(['de' => [$this->file => ['welcome.hello']]], $manifest['targets']);
		$this->assertSame(['welcome' => ['hello' => 'Bonjour {name}']], (new LangFileParser)->parse($php, 'php'));
	}

	/**
	 * La fusion crée le fichier de la langue (tabulations, apostrophes) et n'écrase jamais une valeur existante.
	 */
	public function test_merge_writes_file_and_never_overwrites(): void
	{
		$language = $this->makeApp();

		$report = $language->merge($this->file, 'de', ['welcome.title' => 'Willkommen', 'welcome.hello' => 'Hallo {name}', 'logout' => 'Abmelden']);
		$path = $this->root.'/app/Language/de/App.php';

		$this->assertSame(3, $report->merged);
		$this->assertSame(['welcome' => ['title' => 'Willkommen', 'hello' => 'Hallo {name}'], 'logout' => 'Abmelden'], (new LangFileParser)->parse(file_get_contents($path), 'php'));
		$this->assertStringContainsString("\t'logout' => 'Abmelden',", file_get_contents($path));

		$again = $language->merge($this->file, 'de', ['logout' => 'Autre', 'welcome.title' => 'Autre']);
		$this->assertSame(0, $again->merged);
		$this->assertSame(2, $again->kept);
		$this->assertStringContainsString('Abmelden', file_get_contents($path));
	}

	/**
	 * Marqueurs différents, traduction vide, clé inconnue, langue ou chemin dangereux : refus, rien n'est écrit.
	 */
	public function test_bad_translations_and_paths_are_rejected(): void
	{
		$language = $this->makeApp();

		$report = $language->merge($this->file, 'de', ['welcome.hello' => 'Hallo {nom}', 'logout' => ' ', 'inconnue' => 'x']);
		$this->assertSame(0, $report->merged);
		$this->assertCount(3, $report->rejected);
		$this->assertFileDoesNotExist($this->root.'/app/Language/de/App.php');

		$this->assertNotSame([], $language->merge($this->file, '../../evil', ['logout' => 'x'])->errors);
		$this->assertNotSame([], $language->merge('app/Language/fr/../../../etc/x.php', 'de', ['a' => 'b'])->errors);
		$this->assertNotSame([], $language->merge('app/Language/fr/Absent.php', 'de', ['a' => 'b'])->errors);
	}

	/**
	 * Cycle complet : envoi, attente, fusion à la fin, confirmation ; puis plus rien à traduire.
	 */
	public function test_full_cycle_send_sync_merge_ack(): void
	{
		$language = $this->makeApp();
		$hub = new FakeHub;
		$store = new MemoryJobStore;
		$sender = $this->sender($language, $hub, $store);
		$syncer = new Syncer($hub, $store, $this->merger($language), $sender, ['interval' => 60, 'max_interval' => 3600, 'max_age_days' => 14]);

		$sent = $sender->send();
		$this->assertSame('sent', $sent['outcome']);
		$this->assertSame('duplicate', $sender->send()['outcome']);

		$store->update(1, ['next_poll_at' => 0]);
		$this->assertSame('waiting', $syncer->run()[0]['state']);

		$hub->status = 'completed';
		$hub->translations = ['de' => [
			$this->file => ['welcome.title' => 'Willkommen', 'welcome.hello' => 'Hallo {name}', 'logout' => 'Abmelden'],
			self::USERS => ['list' => 'Benutzerliste'],
		]];
		$store->update(1, ['next_poll_at' => 0]);

		$this->assertSame('merged', $syncer->run()[0]['state']);
		$this->assertSame(1, $hub->acks);
		$this->assertSame(4, $store->find(1)['report']['merged']);
		$this->assertFileExists($this->root.'/app/Language/de/Admin/Users.php');
		$this->assertSame('nothing', $sender->send()['outcome']);
	}

	/**
	 * Hub injoignable : l'archive reste en boîte d'envoi, puis part avec la même clé d'idempotence.
	 */
	public function test_unreachable_hub_queues_then_retries(): void
	{
		$language = $this->makeApp();
		$hub = new FakeHub;
		$hub->down = true;
		$store = new MemoryJobStore;
		$sender = $this->sender($language, $hub, $store);
		$syncer = new Syncer($hub, $store, $this->merger($language), $sender, ['interval' => 60, 'max_interval' => 3600, 'max_age_days' => 14]);

		$this->assertSame('queued', $sender->send()['outcome']);
		$this->assertFileExists($store->find(1)['archive_path']);

		$hub->down = false;
		$store->update(1, ['next_poll_at' => 0]);
		$this->assertSame('sent', $syncer->run()[0]['state']);
		$this->assertArrayHasKey($store->find(1)['uuid'], $hub->received);
	}

	/**
	 * Le suivi SQLite garde les envois : création, empreinte active, mise à jour, envois dus, rapport JSON.
	 */
	public function test_sqlite_store(): void
	{
		$this->root = sys_get_temp_dir().'/lfc'.bin2hex(random_bytes(4));
		$store = new SqliteJobStore($this->root.'/jobs.sqlite');

		$id = $store->create('abc', ['de', 'es'], 5, '/tmp/a.zip');
		$this->assertSame(['de', 'es'], $store->find($id)['locales']);
		$this->assertSame($id, $store->activeByChecksum('abc')['id']);
		$this->assertCount(1, $store->due());

		$store->update($id, ['reference' => 'ref1', 'status' => 'sent', 'report' => ['merged' => 2], 'next_poll_at' => time() + 600]);
		$this->assertSame('ref1', $store->findByReference('ref1')['status'] === 'sent' ? 'ref1' : '');
		$this->assertSame(['merged' => 2], $store->find($id)['report']);
		$this->assertSame([], $store->due());

		$store->update($id, ['status' => 'merged']);
		$this->assertNull($store->activeByChecksum('abc'));
		$this->assertSame(1, count($store->all()));
	}

	/**
	 * Émetteur sur la source donnée.
	 */
	private function sender($language, FakeHub $hub, MemoryJobStore $store): Sender
	{
		return new Sender($this->detector($language), $this->archive($language), $hub, $store, [
			'client_ref' => 'app-1', 'delivery' => 'local', 'webhook_url' => null, 'source' => 'fr', 'locales' => ['de'],
			'outbox' => $this->root.'/outbox', 'interval' => 60,
		]);
	}
}
