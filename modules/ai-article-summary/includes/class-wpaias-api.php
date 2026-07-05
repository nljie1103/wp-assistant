<?php
/**
 * AI 接口请求处理。
 * 兼容 OpenAI 风格 / Gemini / Claude，三种主流请求体格式。
 *
 * @package WP_AI_Article_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPAIAS_API
 */
class WPAIAS_API {

	/**
	 * 调用 AI 生成摘要。
	 *
	 * @param string $content    需要生成摘要的纯文本内容。
	 * @param array  $settings   插件设置数组（包含 provider/model/api_keys 等）。
	 * @param array  $overrides  覆盖参数（可选）：endpoint/model/current_api_key 等。
	 * @return array { 'success' => bool, 'data' => string|null, 'message' => string }
	 */
	public static function generate_summary( $content, $settings, $overrides = array() ) {
		$content = trim( wp_strip_all_tags( (string) $content ) );

		if ( '' === $content ) {
			return array(
				'success' => false,
				'data'    => null,
				'message' => __( '文章内容为空，无法生成摘要。', 'wp-ai-article-summary' ),
			);
		}

		// 合并参数。
		$provider_key = isset( $overrides['provider'] ) ? $overrides['provider'] : ( isset( $settings['provider'] ) ? $settings['provider'] : 'openai' );
		$provider     = WPAIAS_Providers::get( $provider_key );

		if ( ! $provider ) {
			return array(
				'success' => false,
				'data'    => null,
				'message' => __( '未知的服务商。', 'wp-ai-article-summary' ),
			);
		}

		$model      = isset( $overrides['model'] ) ? $overrides['model'] : ( isset( $settings['model'] ) ? $settings['model'] : '' );
		$api_key    = array_key_exists( 'current_api_key', $overrides ) ? $overrides['current_api_key'] : null;
		$endpoint   = isset( $overrides['endpoint'] ) ? $overrides['endpoint'] : ( isset( $settings['custom_endpoint'] ) ? $settings['custom_endpoint'] : '' );
		$temperature = isset( $overrides['temperature'] ) ? (float) $overrides['temperature'] : (float) ( isset( $settings['temperature'] ) ? $settings['temperature'] : 0.7 );
		$max_tokens  = isset( $overrides['max_tokens'] ) ? (int) $overrides['max_tokens'] : (int) ( isset( $settings['max_tokens'] ) ? $settings['max_tokens'] : 512 );
		$prompt_tpl  = isset( $overrides['prompt'] ) ? $overrides['prompt'] : ( isset( $settings['prompt'] ) ? $settings['prompt'] : '' );
		$word_limit  = isset( $settings['word_limit'] ) ? (int) $settings['word_limit'] : 150;

		// 自定义接口模式：用自定义端点 + 自定义模型名。
		if ( 'custom' === $provider_key ) {
			if ( '' === $endpoint ) {
				return array(
					'success' => false,
					'data'    => null,
					'message' => __( '请填写自定义接口地址。', 'wp-ai-article-summary' ),
				);
			}
			if ( '' === $model ) {
				$model = isset( $settings['custom_model'] ) ? $settings['custom_model'] : '';
			}
		} else {
			// 预设服务商：默认 endpoint 使用配置项。
			if ( '' === $endpoint ) {
				$endpoint = $provider['endpoint'];
			}
		}

		if ( null === $api_key ) {
			$api_key = WPAIAS_Plugin::get_api_key_for_model( $settings, $provider_key, $model );
		}
		$api_key = str_replace( array( "\r", "\n" ), '', trim( (string) $api_key ) );

		// 自动补全 endpoint：OpenAI 兼容协议下用户只填写了 base URL 时，自动追加 /chat/completions。
		$format = isset( $provider['format'] ) ? $provider['format'] : 'openai';
		$endpoint = self::normalize_endpoint( $endpoint, $format );

		if ( '' === $api_key ) {
			return array(
				'success' => false,
				'data'    => null,
				'message' => __( '请填写 API Key。', 'wp-ai-article-summary' ),
			);
		}

		if ( '' === $model ) {
			return array(
				'success' => false,
				'data'    => null,
				'message' => __( '请选择或填写模型名。', 'wp-ai-article-summary' ),
			);
		}

		// 组装 Prompt。
		if ( '' === trim( (string) $prompt_tpl ) ) {
			$prompt_tpl = __( '你是一位专业的中文文章编辑助手，请用简洁、客观、流畅的中文为以下文章生成一段摘要，字数控制在 {WORDS} 字以内，不要使用 Markdown 标记，不要重复标题，直接输出摘要正文：\n\n{CONTENT}', 'wp-ai-article-summary' );
		}

		$prompt = str_replace(
			array( '{WORDS}', '{CONTENT}' ),
			array( (string) $word_limit, $content ),
			$prompt_tpl
		);

		// 根据格式分发。
		$format = isset( $provider['format'] ) ? $provider['format'] : 'openai';

		try {
			switch ( $format ) {
				case 'gemini':
					return self::request_gemini( $endpoint, $api_key, $model, $prompt, $temperature, $max_tokens );

				case 'claude':
					return self::request_claude( $endpoint, $api_key, $model, $prompt, $temperature, $max_tokens );

				case 'openai':
				default:
					return self::request_openai( $endpoint, $api_key, $model, $prompt, $temperature, $max_tokens, $provider_key );
			}
		} catch ( Exception $e ) {
			return array(
				'success' => false,
				'data'    => null,
				'message' => $e->getMessage(),
			);
		}
	}

	/**
	 * OpenAI 兼容请求。
	 *
	 * @param string $endpoint    接口地址。
	 * @param string $api_key     Key。
	 * @param string $model       模型名。
	 * @param string $prompt      提示词。
	 * @param float  $temperature 温度。
	 * @param int    $max_tokens  最大 tokens。
	 * @param string $provider_key 服务商 key。
	 * @return array
	 */
	protected static function request_openai( $endpoint, $api_key, $model, $prompt, $temperature, $max_tokens, $provider_key = '' ) {
		$body = array(
			'model'       => $model,
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'temperature' => max( 0, min( 2, (float) $temperature ) ),
			'stream'      => false,
		);

		$token_key          = self::openai_token_limit_key( $provider_key, $model );
		$body[ $token_key ] = max( 16, (int) $max_tokens );

		$response = wp_remote_post(
			esc_url_raw( $endpoint ),
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'data'    => null,
				'message' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$err = self::extract_error_message( $data, $raw );
			return array(
				'success' => false,
				'data'    => null,
				'message' => sprintf( /* translators: 1: http code, 2: error message */ __( '接口返回 HTTP %1$d：%2$s', 'wp-ai-article-summary' ), $code, $err ),
			);
		}

		$text = '';
		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			$text = (string) $data['choices'][0]['message']['content'];
		} elseif ( isset( $data['choices'][0]['text'] ) ) {
			$text = (string) $data['choices'][0]['text'];
		} elseif ( isset( $data['data']['content'] ) ) {
			$text = (string) $data['data']['content'];
		}

		$text = self::sanitize_output( $text );

		if ( '' === $text ) {
			return array(
				'success' => false,
				'data'    => null,
				'message' => __( '接口返回内容为空。', 'wp-ai-article-summary' ),
			);
		}

		return array(
			'success' => true,
			'data'    => $text,
			'message' => 'ok',
		);
	}

	/**
	 * 获取 OpenAI 兼容接口的 token 上限字段名。
	 *
	 * 部分新版模型/厂商已偏向使用 max_completion_tokens，但大量 OpenAI
	 * 兼容厂商仍使用 max_tokens；这里仅对明确需要新版字段的模型做切换。
	 *
	 * @param string $provider_key 服务商 key。
	 * @param string $model        模型名。
	 * @return string
	 */
	protected static function openai_token_limit_key( $provider_key, $model ) {
		$model = strtolower( (string) $model );

		if ( 'kimi' === $provider_key ) {
			return 'max_completion_tokens';
		}

		if ( 'openai' === $provider_key ) {
			if (
				0 === strpos( $model, 'gpt-5' ) ||
				0 === strpos( $model, 'o1' ) ||
				0 === strpos( $model, 'o3' ) ||
				0 === strpos( $model, 'o4' )
			) {
				return 'max_completion_tokens';
			}
		}

		return 'max_tokens';
	}

	/**
	 * Gemini 请求。
	 */
	protected static function request_gemini( $endpoint, $api_key, $model, $prompt, $temperature, $max_tokens ) {
		$url = str_replace( '{model}', rawurlencode( $model ), $endpoint );
		$url = add_query_arg( 'key', $api_key, $url );

		$body = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => array(
				'temperature'     => max( 0, min( 2, (float) $temperature ) ),
				'maxOutputTokens' => max( 16, (int) $max_tokens ),
			),
		);

		$response = wp_remote_post(
			esc_url_raw( $url ),
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'data'    => null,
				'message' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$err = self::extract_error_message( $data, $raw );
			return array(
				'success' => false,
				'data'    => null,
				'message' => sprintf( /* translators: 1: http code, 2: error message */ __( '接口返回 HTTP %1$d：%2$s', 'wp-ai-article-summary' ), $code, $err ),
			);
		}

		$text = '';
		if ( isset( $data['candidates'][0]['content']['parts'] ) && is_array( $data['candidates'][0]['content']['parts'] ) ) {
			foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}

		$text = self::sanitize_output( $text );

		if ( '' === $text ) {
			return array(
				'success' => false,
				'data'    => null,
				'message' => __( '接口返回内容为空。', 'wp-ai-article-summary' ),
			);
		}

		return array(
			'success' => true,
			'data'    => $text,
			'message' => 'ok',
		);
	}

	/**
	 * Claude 请求。
	 */
	protected static function request_claude( $endpoint, $api_key, $model, $prompt, $temperature, $max_tokens ) {
		$body = array(
			'model'      => $model,
			'max_tokens' => max( 16, (int) $max_tokens ),
			'temperature' => max( 0, min( 1, (float) $temperature ) ),
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);

		$response = wp_remote_post(
			esc_url_raw( $endpoint ),
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'data'    => null,
				'message' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$err = self::extract_error_message( $data, $raw );
			return array(
				'success' => false,
				'data'    => null,
				'message' => sprintf( /* translators: 1: http code, 2: error message */ __( '接口返回 HTTP %1$d：%2$s', 'wp-ai-article-summary' ), $code, $err ),
			);
		}

		$text = '';
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}

		$text = self::sanitize_output( $text );

		if ( '' === $text ) {
			return array(
				'success' => false,
				'data'    => null,
				'message' => __( '接口返回内容为空。', 'wp-ai-article-summary' ),
			);
		}

		return array(
			'success' => true,
			'data'    => $text,
			'message' => 'ok',
		);
	}

	/**
	 * 从 API 错误响应中提取错误信息。
	 *
	 * @param array|null $data 解码后的数组。
	 * @param string     $raw  原始响应体。
	 * @return string
	 */
	protected static function extract_error_message( $data, $raw ) {
		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) ) {
				return (string) $data['error']['message'];
			}
			if ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				return (string) $data['error'];
			}
			if ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
				return (string) $data['message'];
			}
		}
		$raw = (string) $raw;
		if ( strlen( $raw ) > 200 ) {
			$raw = substr( $raw, 0, 200 ) . '...';
		}
		return $raw !== '' ? $raw : __( '未知错误', 'wp-ai-article-summary' );
	}

	/**
	 * 自动补全 endpoint，使用户填写 base URL 也能正常工作。
	 *
	 * @param string $endpoint 用户填写的端点。
	 * @param string $format   请求体格式：openai / gemini / claude。
	 * @return string
	 */
	protected static function normalize_endpoint( $endpoint, $format ) {
		$endpoint = trim( (string) $endpoint );
		if ( '' === $endpoint ) {
			return $endpoint;
		}

		// 去除尾部斜杠（保留协议双斜杠）。
		$endpoint = rtrim( $endpoint, '/' );

		switch ( $format ) {
			case 'openai':
				// 如果用户只填写了 base URL（不含 /chat/completions、/completions 等），自动追加。
				$lower = strtolower( $endpoint );
				if (
					false === strpos( $lower, '/chat/completions' ) &&
					false === strpos( $lower, '/completions' ) &&
					false === strpos( $lower, '/responses' ) &&
					false === strpos( $lower, '/messages' )
				) {
					$endpoint .= '/chat/completions';
				}
				break;

			case 'claude':
				$lower = strtolower( $endpoint );
				if ( false === strpos( $lower, '/messages' ) && false === strpos( $lower, '/complete' ) ) {
					$endpoint .= '/v1/messages';
				}
				break;

			case 'gemini':
				// Gemini 走 {model}:generateContent 占位符，保持原样。
				break;
		}

		return $endpoint;
	}

	/**
	 * 清洗 AI 输出。
	 *
	 * @param string $text 原始文本。
	 * @return string
	 */
	protected static function sanitize_output( $text ) {
		$text = (string) $text;
		// 去除 Markdown 三大标记残留。
		$text = preg_replace( '/```[\s\S]*?```/u', '', $text );
		$text = preg_replace( '/^\s*#{1,6}\s*/mu', '', (string) $text );
		$text = preg_replace( '/\*\*(.*?)\*\*/u', '$1', (string) $text );
		$text = preg_replace( '/\*(.*?)\*/u', '$1', (string) $text );
		// 多余空行折叠。
		$text = preg_replace( "/\n{3,}/u", "\n\n", (string) $text );
		return trim( (string) $text );
	}
}
