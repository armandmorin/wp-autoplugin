<?php

namespace WP_Autoplugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OpenAI_API {
	private $api_key;
	private $model;
	private $temperature = 0.2;
	private $max_tokens  = 4096;

	public function set_api_key( $api_key ) {
		$this->api_key = sanitize_text_field( $api_key );
	}

	public function set_model( $model ) {
		$this->model = sanitize_text_field( $model );

		// Set the temperature and max tokens based on the model.
		$model_params = array(
			'gpt-4o' => array(
				'temperature' => 0.2,
				'max_tokens'  => 4096,
			),
			'gpt-4o-mini' => array(
				'temperature' => 0.2,
				'max_tokens'  => 4096,
			),
			'gpt-4-turbo' => array(
				'temperature' => 0.2,
				'max_tokens'  => 4096,
			),
			'gpt-3.5-turbo' => array(
				'temperature' => 0.2,
				'max_tokens'  => 4096,
			),
		);

		if ( isset( $model_params[ $model ] ) ) {
			$this->temperature = $model_params[ $model ]['temperature'];
			$this->max_tokens  = $model_params[ $model ]['max_tokens'];
		}
	}

	public function send_prompt( $prompt, $system_message = '', $override_body = array() ) {
		$messages = array();
		if ( ! empty( $system_message ) ) {
			$messages[] = array(
				'role' => 'system',
				'content' => $system_message,
			);
		}

		$messages[] = array(
			'role' => 'user',
			'content' => $prompt,
		);

		$body = array(
			'model'       => $this->model,
			'temperature' => $this->temperature,
			'max_tokens'  => $this->max_tokens,
			'messages'    => $messages,
		);

		// Keep only allowed keys in the override body.
		$allowed_keys = array( 'model', 'temperature', 'max_tokens', 'messages', 'response_format' );
		$override_body = array_intersect_key( $override_body, array_flip( $allowed_keys ) );
		$body = array_merge( $body, $override_body );

		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );

		$data = json_decode( $body, true );

		// If finish_reason is "length", the response is too long.
		// We need to send a new request with the whole conversation so far, so the AI can continue from where it left off.
		if ( isset( $data['choices'][0]['finish_reason'] ) && 'length' === $data['choices'][0]['finish_reason'] ) {
			$messages[] = array(
				'role' => 'assistant',
				'content' => $data['choices'][0]['message']['content'],
			);

			$body = array(
				'model'       => $this->model,
				'temperature' => $this->temperature,
				'max_tokens'  => $this->max_tokens,
				'messages'    => $messages,
			);

			$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode( $body ),
			) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$body = wp_remote_retrieve_body( $response );

			$new_data = json_decode( $body, true );

			if ( ! isset( $new_data['choices'][0]['message']['content'] ) ) {
				return new \WP_Error( 'api_error', 'Error communicating with the OpenAI API.' . "\n" . print_r( $new_data, true ) );
			}

			// Merge the new response with the old one.
			$data['choices'][0]['message']['content'] .= $new_data['choices'][0]['message']['content'];
		}

		if ( isset( $data['choices'][0]['message']['content'] ) ) {
			return $data['choices'][0]['message']['content'];
		} else {
			return new \WP_Error( 'api_error', 'Error communicating with the OpenAI API.' . "\n" . print_r( $data, true ) );
		}
	}
}
