<?php

namespace IungoWeb;

use Exception;
use JetBrains\PhpStorm\Pure;
use Symfony\Component\Dotenv\Dotenv;
use Tinify\AccountException;
use function Tinify\compressionCount;
use function Tinify\fromFile;
use function Tinify\setKey;
use function Tinify\validate;

class Compressor {

	private Terminal $terminal;

	private string $diretorio;
	private array $diretoriosBanidos = [
		'.',
		'..',
	];
	private array $tiposImagens = [
		IMAGETYPE_JPEG,
		IMAGETYPE_PNG
	];

	private int $creditosUsados = 0;
	private int $totalImgComprimidas = 0;
	private int $totalDirProcessados = 0;

	/**
	 * Compressor constructor.
	 * @param string $arquivoAutoload
	 */
	public function __construct(private string $arquivoAutoload) {

		$diretorioVendor = dirname($this->arquivoAutoload);

		// Iniciando as variáveis de ambiente
		$dotenv = new Dotenv();
		$dotenv->load($diretorioVendor . '/../.env');
		$dotenv->overload($diretorioVendor . '/../.env.local');

		// Criando objeto que ira exibir mensagens no terminal
		$this->terminal = new Terminal();

		// Setando a chave da API do Tiny
		setKey($_ENV['API_KEY']);

		// Definindo diretório que ira rodar o script
		$this->diretorio = $_ENV['DIRETORIO_RAIZ'] . (str_ends_with($_ENV['DIRETORIO_RAIZ'], '/') ? '' : '/');

		// Só para a função compressionCount() retornar o valor real, exigência da API
		try {
			validate();
		} catch (AccountException $e) {

			$this->terminal->erro('Chave da API foi recusada pela API.' . PHP_EOL . $e->getMessage());
			die();
		}

		// Recuperando a quantidade de imagens já processadas na API
		$this->creditosUsados = (int) compressionCount();

		// Informando processo ao usuário
		$this->terminal->log('Classe iniciada',2);
		$this->terminal->info('DIRETÓRIO RAIZ: ' . $this->diretorio,2);
		$this->terminal->info('COMPRESSÕES JÁ REALIZADAS NESTE MÊS: ' . $this->creditosUsados, 3);
	}

	public function executa() {

		$this->terminal->info('Execução iniciada.');

		$tempoInicial = microtime(true);

		$this->navegaDiretorios($this->diretorio);

		$this->terminal->sucesso('Fim da execução');

		$tempoFinal = microtime(true);

		$tempoTotal = (int) ($tempoFinal - $tempoInicial);

		$this->terminal->novaLinha(5);
		$this->terminal->info('=== Tempo decorrido: ' . $this->textoTempo($tempoTotal));
		$this->terminal->info('=== Diretórios processados: ' . $this->totalDirProcessados);
		$this->terminal->info('=== Imagens comprimidas: ' . $this->totalImgComprimidas);
		$this->terminal->info('=== Compressões free restantes: ' . 500 - ($this->totalImgComprimidas + $this->creditosUsados), 3);

		$this->terminal->info('Acesse: www.iungoweb.io');
	}

	private function navegaDiretorios(string $diretorioAtual): void {

		$this->terminal->novaLinha();
		$this->terminal->log('Entrando no diretório: ' . $diretorioAtual);

		$conteudoDiretorio = scandir($diretorioAtual);

		// Incrementando diretórios processados
		$this->totalDirProcessados++;
		// Informando o número de diretórios processados
		$this->terminal->info('=== Diretórios processados: ' . $this->totalDirProcessados, 2);

		// Recupera só os diretórios e remove os banidos
		$subdiretorios = array_filter($conteudoDiretorio, function ($caminho) use ($diretorioAtual) {
			return
				is_dir($diretorioAtual . $caminho)
				&& !in_array($caminho, $this->diretoriosBanidos);
		});

		// Informando diretórios encontrados
		if (empty($subdiretorios))
			$this->terminal->info('Nenhum diretório encontrado.');
		else
			$this->terminal->info('Diretórios encontrados: ' . implode(',' . PHP_EOL, $subdiretorios) . '.');

		// Recupera só os arquivos e do tipo imagem
		$imagens = array_filter($conteudoDiretorio, function ($caminho) use ($diretorioAtual) {
			return
				is_file($diretorioAtual . $caminho)
				&& in_array(
					exif_imagetype($diretorioAtual . $caminho),
					$this->tiposImagens
				);
		});

		// Informando imagens encontradas
		if (empty($imagens))
			$this->terminal->info('Nenhuma imagem encontrada.');
		else
			$this->terminal->info('Imagens encontrados: ' . implode(',' . PHP_EOL, $imagens) . '.');

		// Comprimindo imagens
		foreach ($imagens as $imagem)
			$this->comprimiImg($diretorioAtual, $imagem);

		// Entrando no subdiretórios
		foreach ($subdiretorios as $diretorio)
			$this->navegaDiretorios($diretorioAtual . $diretorio . '/');
	}

	private function comprimiImg(string $caminho, string $imagem): void {

		$caminhoImagem = $caminho . $imagem;

		$this->terminal->novaLinha();
		$this->terminal->log('[RUN] Comprimindo imagem: ' . $imagem);

		$inicio = microtime(true);
		$tamanhoOriginal = filesize($caminhoImagem);
		$msgErro = null;
		$novoTamanho = 0;

		try {
			$source = fromFile($caminhoImagem);
			$novoTamanho = $source->toFile($caminhoImagem);
		} catch(\Tinify\AccountException $e) {
			// Verify your API key and account limit.
			$msgErro = $e->getMessage();
		} catch(\Tinify\ClientException $e) {
			// Check your source image and request options.
			$msgErro = $e->getMessage();
		} catch(\Tinify\ServerException $e) {
			// Temporary issue with the Tinify API.
			$msgErro = $e->getMessage();
		} catch(\Tinify\ConnectionException $e) {
			// A network connection error occurred.
			$msgErro = $e->getMessage();
		} catch(Exception $e) {
			// Something else went wrong, unrelated to the Tinify API.
			$msgErro = $e->getMessage();
		}

		if ($msgErro) {

			$this->terminal->erro('Erro ao processar imagem: ' . $imagem . PHP_EOL . $msgErro);
			return;
		}

		$this->totalImgComprimidas++;

		$fim = microtime(true);

		$tempoTotal = (int) ($fim - $inicio);

		$this->terminal->info('=== Tempo decorrido: ' . $this->textoTempo($tempoTotal));
		$this->terminal->info('=== Taxa de redução: ' . number_format((100 - ($novoTamanho / $tamanhoOriginal) * 100), 2) . '%');
		$this->terminal->info('=== Imagens comprimidas: ' . $this->totalImgComprimidas);
		$this->terminal->info('=== Compressões free restantes: ' . 500 - ($this->totalImgComprimidas + $this->creditosUsados));
	}

	#[Pure] private function textoTempo(int $segundos): string {

		$tempoAtual = $segundos;
		$unidadeTempo = 's';

		if ($tempoAtual == 0)
			return '< 1s';

		if ($tempoAtual >= 60) {

			$tempoAtual = intval($tempoAtual / 60);
			$unidadeTempo = 'm';
		}

		if ($tempoAtual >= 60) {

			$tempoAtual = intval($tempoAtual / 60);
			$unidadeTempo = 'h';
		}

		return $tempoAtual . $unidadeTempo;
	}
}