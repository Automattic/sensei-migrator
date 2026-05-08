<?php
/**
 * LearnDash source adapter.
 *
 * @package Sensei_Migrator
 */

namespace Sensei_Migrator\Sources\LearnDash;

use Sensei_Migrator\Core\Source_Adapter;

defined( 'ABSPATH' ) || exit;

/**
 * Reads LearnDash CPT and `wp_pro_quiz_*` data directly from the site DB.
 *
 * Phase 1: read paths return normalized records suitable for the dry-run preview.
 * Phase 2 fills in the write side; this class stays read-only.
 */
class LearnDash_Source_Adapter implements Source_Adapter {

	const SLUG = 'learndash';

	const POST_TYPE_COURSE   = 'sfwd-courses';
	const POST_TYPE_LESSON   = 'sfwd-lessons';
	const POST_TYPE_TOPIC    = 'sfwd-topic';
	const POST_TYPE_QUIZ     = 'sfwd-quiz';
	const POST_TYPE_QUESTION = 'sfwd-question';

	const META_COURSE_SETTINGS   = '_sfwd-courses';
	const META_LESSON_SETTINGS   = '_sfwd-lessons';
	const META_TOPIC_SETTINGS    = '_sfwd-topic';
	const META_QUIZ_SETTINGS     = '_sfwd-quiz';
	const META_QUESTION_SETTINGS = '_sfwd-question';

	public function slug(): string {
		return self::SLUG;
	}

	public function label(): string {
		return __( 'LearnDash', 'sensei-migrator' );
	}

	public function detect(): bool {
		return post_type_exists( self::POST_TYPE_COURSE );
	}

	public function inventory(): array {
		if ( ! $this->detect() ) {
			return array(
				'courses'     => 0,
				'lessons'     => 0,
				'quizzes'     => 0,
				'questions'   => 0,
				'enrollments' => 0,
			);
		}

		return array(
			'courses'     => $this->count_posts( self::POST_TYPE_COURSE ),
			'lessons'     => $this->count_posts( self::POST_TYPE_LESSON )
				+ $this->count_posts( self::POST_TYPE_TOPIC ),
			'quizzes'     => $this->count_posts( self::POST_TYPE_QUIZ ),
			'questions'   => $this->count_pro_quiz_questions(),
			'enrollments' => 0, // Phase 3.
		);
	}

	public function read_courses(): iterable {
		$query = new \WP_Query(
			array(
				'post_type'      => self::POST_TYPE_COURSE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $query->posts as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$settings = $this->settings_array( $post_id, self::META_COURSE_SETTINGS );

			yield array(
				'source_id'        => (int) $post_id,
				'title'            => $post->post_title,
				'description'      => $post->post_content,
				'excerpt'          => $post->post_excerpt,
				'status'           => $post->post_status,
				'author_id'        => (int) $post->post_author,
				'menu_order'       => (int) $post->menu_order,
				'lesson_orderby'   => (string) ( $settings['course_lesson_orderby'] ?? '' ),
				'lesson_order'     => (string) ( $settings['course_lesson_order'] ?? '' ),
				'price_type'       => (string) ( $settings['course_price_type'] ?? '' ),
				'prerequisite'     => $this->normalize_int_list( $settings['course_prerequisite'] ?? array() ),
			);
		}

		wp_reset_postdata();
	}

	public function read_lessons(): iterable {
		yield from $this->read_lesson_like( self::POST_TYPE_LESSON, false );
		yield from $this->read_lesson_like( self::POST_TYPE_TOPIC, true );
	}

	public function read_quizzes(): iterable {
		$query = new \WP_Query(
			array(
				'post_type'      => self::POST_TYPE_QUIZ,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $query->posts as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$settings    = $this->settings_array( $post_id, self::META_QUIZ_SETTINGS );
			$pro_quiz_id = (int) ( $settings['quiz_pro'] ?? 0 );
			$master_row  = $pro_quiz_id ? $this->fetch_pro_quiz_master( $pro_quiz_id ) : null;

			yield array(
				'source_id'          => (int) $post_id,
				'source_lesson_id'   => (int) ( $settings['lesson'] ?? 0 ),
				'source_course_id'   => (int) ( $settings['course'] ?? 0 ),
				'source_pro_quiz_id' => $pro_quiz_id,
				'title'              => $post->post_title,
				'content'            => $post->post_content,
				'status'             => $post->post_status,
				'pass_required'      => ! empty( $settings['passingpercentage'] ) || ! empty( $settings['threshold'] ),
				'passmark'           => $this->normalize_passmark( $settings ),
				'random_questions'   => $master_row ? (bool) $master_row->question_random : false,
				'random_answers'     => $master_row ? (bool) $master_row->answer_random : false,
				'time_limit'         => $master_row ? (int) $master_row->time_limit : 0,
				'attempts_allowed'   => (string) ( $settings['repeats'] ?? '' ),
			);
		}

		wp_reset_postdata();
	}

	public function read_questions(): iterable {
		global $wpdb;

		$table = $this->pro_quiz_table( 'question' );
		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Source-side read; cache not relevant during one-shot migration.
		$rows = $wpdb->get_results(
			"SELECT id, quiz_id, online, sort, title, question, points, answer_type,
			        answer_data, tip_msg, correct_msg, incorrect_msg, category_id
			 FROM `{$table}`
			 WHERE online = 1
			 ORDER BY quiz_id ASC, sort ASC"
		);

		foreach ( (array) $rows as $row ) {
			yield array(
				'source_id'      => (int) $row->id,
				'source_quiz_id' => (int) $row->quiz_id,
				'sort'           => (int) $row->sort,
				'title'          => (string) $row->title,
				'question_html'  => (string) $row->question,
				'points'         => (int) $row->points,
				'answer_type'    => (string) $row->answer_type,
				'answer_data'    => $this->maybe_unserialize( (string) $row->answer_data ),
				'tip'            => $row->tip_msg !== '' ? (string) $row->tip_msg : null,
				'correct_msg'    => $row->correct_msg !== '' ? (string) $row->correct_msg : null,
				'incorrect_msg'  => $row->incorrect_msg !== '' ? (string) $row->incorrect_msg : null,
				'category_id'    => (int) $row->category_id,
			);
		}
	}

	public function read_enrollments(): iterable {
		// Phase 3.
		return array();
	}

	private function read_lesson_like( string $post_type, bool $is_topic ): iterable {
		$settings_key = $is_topic ? self::META_TOPIC_SETTINGS : self::META_LESSON_SETTINGS;

		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $query->posts as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$settings = $this->settings_array( $post_id, $settings_key );

			$source_course_id = $is_topic
				? $this->resolve_topic_course( (int) ( $settings['lesson'] ?? 0 ) )
				: (int) ( $settings['lesson_course'] ?? 0 );

			yield array(
				'source_id'           => (int) $post_id,
				'source_course_id'    => $source_course_id,
				'source_lesson_parent' => $is_topic ? (int) ( $settings['lesson'] ?? 0 ) : 0,
				'is_topic'            => $is_topic,
				'title'               => $post->post_title,
				'content'             => $post->post_content,
				'excerpt'             => $post->post_excerpt,
				'status'              => $post->post_status,
				'author_id'           => (int) $post->post_author,
				'menu_order'          => (int) $post->menu_order,
				'sample'              => ! empty( $settings[ $is_topic ? 'sample_topic' : 'sample_lesson' ] ),
				'duration'            => (string) ( $settings[ $is_topic ? 'topic_duration' : 'lesson_duration' ] ?? '' ),
			);
		}

		wp_reset_postdata();
	}

	private function resolve_topic_course( int $parent_lesson_id ): int {
		if ( ! $parent_lesson_id ) {
			return 0;
		}
		$lesson_settings = $this->settings_array( $parent_lesson_id, self::META_LESSON_SETTINGS );
		return (int) ( $lesson_settings['lesson_course'] ?? 0 );
	}

	private function settings_array( int $post_id, string $meta_key ): array {
		$value = get_post_meta( $post_id, $meta_key, true );
		return is_array( $value ) ? $value : array();
	}

	private function normalize_passmark( array $settings ): float {
		if ( isset( $settings['passingpercentage'] ) ) {
			return (float) $settings['passingpercentage'];
		}
		if ( isset( $settings['threshold'] ) ) {
			return (float) $settings['threshold'] * 100;
		}
		return 0.0;
	}

	private function normalize_int_list( $value ): array {
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'intval', $value ) ) );
		}
		if ( is_string( $value ) && '' !== $value ) {
			return array_values( array_filter( array_map( 'intval', explode( ',', $value ) ) ) );
		}
		return array();
	}

	private function count_posts( string $post_type ): int {
		$counts = wp_count_posts( $post_type );
		if ( ! $counts ) {
			return 0;
		}
		$total = 0;
		foreach ( (array) $counts as $count ) {
			$total += (int) $count;
		}
		return $total;
	}

	private function count_pro_quiz_questions(): int {
		global $wpdb;
		$table = $this->pro_quiz_table( 'question' );
		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE online = 1" );
	}

	private function fetch_pro_quiz_master( int $pro_quiz_id ): ?\stdClass {
		global $wpdb;
		$table = $this->pro_quiz_table( 'master' );
		if ( ! $this->table_exists( $table ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $pro_quiz_id ) );
		return $row ?: null;
	}

	/**
	 * Build the table name for a WP-Pro-Quiz table.
	 *
	 * LearnDash 3.x uses `{$wpdb->prefix}learndash_pro_quiz_<suffix>`. Older WP-Pro-Quiz
	 * installations used `{$wpdb->prefix}wp_pro_quiz_<suffix>`. Try the LD-namespaced
	 * name first; fall back to the legacy name if needed.
	 */
	private function pro_quiz_table( string $suffix ): string {
		global $wpdb;
		$ld_namespaced = $wpdb->prefix . 'learndash_pro_quiz_' . $suffix;
		if ( $this->table_exists( $ld_namespaced ) ) {
			return $ld_namespaced;
		}
		return $wpdb->prefix . 'wp_pro_quiz_' . $suffix;
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	/**
	 * WP-Pro-Quiz historically stores answer_data as PHP-serialized objects, though
	 * some installations have JSON. Try serialized first, then JSON, otherwise pass
	 * the raw string through.
	 */
	private function maybe_unserialize( string $value ) {
		if ( '' === $value ) {
			return null;
		}
		if ( is_serialized( $value ) ) {
			$unserialized = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $unserialized ) {
				return $unserialized;
			}
		}
		$decoded = json_decode( $value, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			return $decoded;
		}
		return $value;
	}
}
