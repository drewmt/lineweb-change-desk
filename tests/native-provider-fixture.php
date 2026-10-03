<?php
namespace Lineweb\ChangeDesk\Tests;

use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\{Candidate, GenerativeAiResult, TokenUsage};
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Messages\DTO\{MessagePart, ModelMessage};
/** CLI-only, in-memory native SDK fixture. No key, network request or runtime registration. */
final class FixtureProvider extends AbstractProvider {
	public static int $calls   = 0;
	public static string $mode = 'success';
	public static $before      = null;
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata( 'lwcd-fixture', 'Synthetic test only', ProviderTypeEnum::server() );
	}
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new class() implements ProviderAvailabilityInterface {public function isConfigured(): bool {
				return true;
		}};
	}
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new class() implements ModelMetadataDirectoryInterface {
			public function listModelMetadata(): array {
				return array( $this->getModelMetadata( 'synthetic' ) );
			}
			public function hasModelMetadata( string $id ): bool {
				return 'synthetic' === $id;
			}
			public function getModelMetadata( string $id ): ModelMetadata {
				return new ModelMetadata( 'synthetic', 'Synthetic', array( CapabilityEnum::textGeneration() ), array( new SupportedOption( OptionEnum::inputModalities() ), new SupportedOption( OptionEnum::outputModalities() ), new SupportedOption( OptionEnum::outputMimeType() ), new SupportedOption( OptionEnum::outputSchema() ), new SupportedOption( OptionEnum::maxTokens() ) ) );
			}
		};
	}
	protected static function createModel( ModelMetadata $metadata, ProviderMetadata $provider ): ModelInterface {
		return new FixtureModel( $metadata, $provider );
	}
}
final class FixtureModel implements ModelInterface, TextGenerationModelInterface {
	private ModelConfig $config;
	public function __construct( private ModelMetadata $metadata, private ProviderMetadata $provider ) {
		$this->config = new ModelConfig();
	}
	public function metadata(): ModelMetadata {
		return $this->metadata;
	}
	public function providerMetadata(): ProviderMetadata {
		return $this->provider;
	}
	public function setConfig( ModelConfig $config ): void {
		$this->config = $config;
	}
	public function getConfig(): ModelConfig {
		return $this->config;
	}
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		++FixtureProvider::$calls;
		if ( FixtureProvider::$before ) {
			( FixtureProvider::$before )();
		}
		if ( 'timeout' === FixtureProvider::$mode ) {
			throw new \RuntimeException( 'Synthetic timeout' );
		}
		$text     = $prompt[0]->getParts()[0]->getText();
		$fields   = json_decode( explode( "UNTRUSTED SOURCE FIELDS:\n", $text )[1], true );
		$response = wp_json_encode(
			array(
				'reviewed_ids' => array_column( $fields, 'field_id' ),
				'proposals'    => array(),
			)
		);
		if ( 'malformed' === FixtureProvider::$mode ) {
			$response = '{"proposals":';
		}
		return new GenerativeAiResult( 'synthetic', array( new Candidate( new ModelMessage( array( new MessagePart( $response ) ) ), FinishReasonEnum::stop() ) ), new TokenUsage( 1, 1, 2 ), $this->provider, $this->metadata );
	}
}
