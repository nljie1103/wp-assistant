<?php
/**
 * 服务商与模型预设清单（三级联动数据源）。
 *
 * @package WP_AI_Article_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPAIAS_Providers
 */
class WPAIAS_Providers {

	/**
	 * 获取全部服务商配置（预设列表）。
	 *
	 * 字段说明：
	 *  - label    : 显示名称
	 *  - endpoint : 默认接口地址（chat/completions 风格）
	 *  - format   : 请求体格式（openai / gemini / claude / custom）
	 *  - models   : 内置模型清单
	 *  - auth     : 鉴权类型（bearer / header_key / url_key / x-api-key）
	 *  - auth_header : 自定义鉴权 header 名（可选）
	 *
	 * @return array
	 */
	public static function all() {
		return array(
			'openai' => array(
				'label'    => 'OpenAI',
				'endpoint' => 'https://api.openai.com/v1/chat/completions',
				'format'   => 'openai',
				'auth'     => 'bearer',
				'models'   => array(
					'gpt-5.4-nano',
					'gpt-5.4-mini',
					'gpt-5.5',
					'gpt-5.4',
					'gpt-5.1',
					'gpt-4.1',
					'chat-latest',
				),
			),
			'gemini' => array(
				'label'    => 'Gemini（Google）',
				'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
				'format'   => 'gemini',
				'auth'     => 'url_key',
				'models'   => array(
					'gemini-3-flash-preview',
					'gemini-3-pro-preview',
					'gemini-2.5-flash',
					'gemini-2.5-flash-lite',
					'gemini-2.5-pro',
				),
			),
			'deepseek' => array(
				'label'    => 'DeepSeek',
				'endpoint' => 'https://api.deepseek.com/chat/completions',
				'format'   => 'openai',
				'auth'     => 'bearer',
				'models'   => array(
					'deepseek-v4-flash',
					'deepseek-v4-pro',
					'deepseek-chat',
					'deepseek-reasoner',
				),
			),
			'volcengine' => array(
				'label'    => '火山方舟（字节）',
				'endpoint' => 'https://ark.cn-beijing.volces.com/api/v3/chat/completions',
				'format'   => 'openai',
				'auth'     => 'bearer',
				'models'   => array(
					'doubao-seed-2-0-lite-260215',
					'doubao-seed-2-0-pro-260215',
					'doubao-seed-2-0-mini-260215',
					'doubao-seed-2-0-code-260215',
					'doubao-seed-1-6-250615',
					'doubao-1-5-pro-256k-250115',
				),
			),
			'kimi' => array(
				'label'    => 'Kimi（月之暗面）',
				'endpoint' => 'https://api.moonshot.ai/v1/chat/completions',
				'format'   => 'openai',
				'auth'     => 'bearer',
				'models'   => array(
					'kimi-k2.6',
					'kimi-k2.5',
					'kimi-k2',
					'kimi-k2-thinking',
					'moonshot-v1',
				),
			),
			'openrouter' => array(
				'label'    => 'OpenRouter',
				'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
				'format'   => 'openai',
				'auth'     => 'bearer',
				'models'   => array(
					'openai/gpt-5.5',
					'openai/gpt-5.4-mini',
					'anthropic/claude-opus-4.8',
					'anthropic/claude-sonnet-4.6',
					'google/gemini-3-flash-preview',
					'google/gemini-3-pro-preview',
					'deepseek/deepseek-v4-pro',
					'moonshotai/kimi-k2.6',
					'qwen/qwen3.6-plus',
					'mistralai/mistral-medium-3.5',
				),
			),
			'claude' => array(
				'label'    => 'Claude（Anthropic）',
				'endpoint' => 'https://api.anthropic.com/v1/messages',
				'format'   => 'claude',
				'auth'     => 'x-api-key',
				'models'   => array(
					'claude-opus-4-8',
					'claude-sonnet-4-6',
					'claude-haiku-4-5-20251001',
					'claude-sonnet-4-5-20250929',
					'claude-sonnet-4-20250514',
					'claude-3-7-sonnet-20250219',
				),
			),
			'qwen' => array(
				'label'    => '通义千问（阿里）',
				'endpoint' => 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions',
				'format'   => 'openai',
				'auth'     => 'bearer',
				'models'   => array(
					'qwen3.7-max',
					'qwen3.6-plus',
					'qwen3.5-plus',
					'qwen3.5-flash',
					'qwen-plus-latest',
					'qwen-turbo-latest',
					'qwen-long',
				),
			),
			'spark' => array(
				'label'    => '讯飞星火',
				'endpoint' => 'https://spark-api-open.xf-yun.com/v1/chat/completions',
				'format'   => 'openai',
				'auth'     => 'bearer',
				'models'   => array(
					'spark-lite',
					'spark-pro',
					'spark-pro-128k',
					'spark-max',
					'spark-ultra',
					'4.0Ultra',
				),
			),
			'glm' => array(
				'label'    => '智谱 GLM',
				'endpoint' => 'https://open.bigmodel.cn/api/paas/v4/chat/completions',
				'format'   => 'openai',
				'auth'     => 'bearer',
				'models'   => array(
					'glm-5.1',
					'glm-5',
					'glm-5-flash',
					'glm-4.5',
					'glm-4-plus',
					'glm-4-flash',
				),
			),
			'ai360' => array(
				'label'    => '360 智脑',
				'endpoint' => 'https://api.360.cn/v1/chat/completions',
				'format'   => 'openai',
				'auth'     => 'bearer',
				'models'   => array(
					'360gpt2-pro',
					'360gpt-pro',
					'deepseek-v3-360gpt-pro',
					'qwen-plus-360gpt-pro',
					'360gpt-turbo',
				),
			),
			'ernie' => array(
				'label'    => '百度文心一言',
				'endpoint' => 'https://qianfan.baidubce.com/v2/chat/completions',
				'format'   => 'openai',
				'auth'     => 'bearer',
				'models'   => array(
					'ernie-4.5-turbo-128k',
					'ernie-4.0-turbo-8k',
					'ernie-x1-turbo-32k',
					'ernie-speed-128k',
					'ernie-lite-8k',
				),
			),
			'doubao' => array(
				'label'    => '豆包（字节）',
				'endpoint' => 'https://ark.cn-beijing.volces.com/api/v3/chat/completions',
				'format'   => 'openai',
				'auth'     => 'bearer',
				'models'   => array(
					'doubao-seed-2-0-lite-260215',
					'doubao-seed-2-0-pro-260215',
					'doubao-seed-2-0-mini-260215',
					'doubao-seed-2-0-code-260215',
					'doubao-seed-1-6-250615',
					'doubao-1-5-pro-256k-250115',
				),
			),
			'mistral' => array(
				'label'    => 'Mistral',
				'endpoint' => 'https://api.mistral.ai/v1/chat/completions',
				'format'   => 'openai',
				'auth'     => 'bearer',
				'models'   => array(
					'mistral-medium-3.5',
					'mistral-small-latest',
					'mistral-large-latest',
					'mistral-medium-latest',
					'ministral-14b-latest',
					'codestral-latest',
				),
			),
			'grok' => array(
				'label'    => 'Grok（xAI）',
				'endpoint' => 'https://api.x.ai/v1/chat/completions',
				'format'   => 'openai',
				'auth'     => 'bearer',
				'models'   => array(
					'grok-4.3',
					'grok-4.20',
					'grok-4.20-reasoning',
					'grok-3',
					'grok-3-fast',
					'grok-3-mini',
				),
			),
			'custom' => array(
				'label'    => '自定义接口',
				'endpoint' => '',
				'format'   => 'openai',
				'auth'     => 'bearer',
				'models'   => array(),
			),
		);
	}

	/**
	 * 获取指定服务商配置。
	 *
	 * @param string $key 服务商 key。
	 * @return array|null
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * 输出给前端 JS 的 JSON 模型映射。
	 *
	 * @return array
	 */
	public static function js_map() {
		$map = array();
		foreach ( self::all() as $key => $cfg ) {
			$map[ $key ] = array(
				'label'    => $cfg['label'],
				'endpoint' => $cfg['endpoint'],
				'models'   => $cfg['models'],
			);
		}
		return $map;
	}
}
