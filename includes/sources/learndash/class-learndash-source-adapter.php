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
		return 'LearnDash';
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
			'enrollments' => 0,
		);
	}

	public function read_courses(): iterable {
		foreach ( $this->paginated_post_ids( self::POST_TYPE_COURSE ) as $post_id ) {
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
	}

	public function read_lessons(): iterable {
		yield from $this->read_lesson_like( self::POST_TYPE_LESSON, false );
		yield from $this->read_lesson_like( self::POST_TYPE_TOPIC, true );
	}

	public function read_quizzes(): iterable {
		foreach ( $this->paginated_post_ids( self::POST_TYPE_QUIZ ) as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$settings    = $this->settings_array( $post_id, self::META_QUIZ_SETTINGS );
			$pro_quiz_id = (int) ( $settings['quiz_pro'] ?? 0 );
			$master_row  = $pro_quiz_id ? $this->fetch_pro_quiz_master( $pro_quiz_id ) : null;

			$source_lesson_id = (int) ( $settings['lesson'] ?? 0 );
			$source_course_id = (int) ( $settings['course'] ?? 0 );
			if ( ! $source_course_id && $source_lesson_id ) {
				$source_course_id = $this->resolve_lesson_course(
					$source_lesson_id,
					$this->settings_array( $source_lesson_id, self::META_LESSON_SETTINGS )
				);
			}
			if ( ! $source_lesson_id ) {
				$source_lesson_id = (int) wp_get_post_parent_id( $post_id );
			}

			yield array(
				'source_id'          => (int) $post_id,
				'source_lesson_id'   => $source_lesson_id,
				'source_course_id'   => $source_course_id,
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
	}

	public function read_questions(): iterable {
		global $wpdb;

		$table = $this->pro_quiz_table( 'question' );
		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		$batch_size = 200;
		$offset     = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Source-side read; cache not relevant during one-shot migration.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, quiz_id, online, sort, title, question, points, answer_type,
					        answer_data, tip_msg, correct_msg, incorrect_msg, category_id
					 FROM `{$table}`
					 WHERE online = 1
					 ORDER BY quiz_id ASC, sort ASC, id ASC
					 LIMIT %d OFFSET %d",
					$batch_size,
					$offset
				)
			);

			foreach ( (array) $rows as $row ) {
				yield array(
					'source_id'      => (int) $row->id,
					'source_quiz_id' => (int) $row->quiz_id,
					'sort'           => (int) $row->sort,
					'title'          => (string) $row->title,
					'question_html' => (string) $row->question,
					'points'         => (int) $row->points,
					'answer_type'    => (string) $row->answer_type,
					'answer_data'    => $this->maybe_unserialize( (string) $row->answer_data ),
					'tip'            => $row->tip_msg !== '' ? (string) $row->tip_msg : null,
					'correct_msg'    => $row->correct_msg !== '' ? (string) $row->correct_msg : null,
					'incorrect_msg'  => $row->incorrect_msg !== '' ? (string) $row->incorrect_msg : null,
					'category_id'    => (int) $row->category_id,
				);
			}

			$returned = count( (array) $rows );
			$offset  += $batch_size;
		} while ( $returned === $batch_size );
	}

	public function read_enrollments(): iterable {
		return array();
	}

	/**
	 * Yields lesson records. Topics are read with `$is_topic = true` and emitted as lessons
	 * with `is_topic` set so the field mapper can flatten them into Sensei's flat lesson list.
	 */
	private function read_lesson_like( string $post_type, bool $is_topic ): iterable {
		$settings_key = $is_topic ? self::META_TOPIC_SETTINGS : self::META_LESSON_SETTINGS;

		foreach ( $this->paginated_post_ids( $post_type ) as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}

			$settings = $this->settings_array( $post_id, $settings_key );

			$source_course_id = $is_topic
				? $this->resolve_topic_course( (int) $post_id, (int) ( $settings['lesson'] ?? 0 ) )
				: $this->resolve_lesson_course( (int) $post_id, $settings );

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
	}

	/**
	 * Walks all post IDs of a given type in fixed-size batches, streaming one ID at
	 * a time so callers never hold the whole result set in memory.
	 */
	private function paginated_post_ids( string $post_type ): iterable {
		$batch_size = 200;
		$page       = 1;

		do {
			$query = new \WP_Query(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => $batch_size,
					'paged'          => $page,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);

			foreach ( $query->posts as $post_id ) {
				yield $post_id;
			}

			$returned = count( $query->posts );
			++$page;
		} while ( $returned === $batch_size );
	}

	/**
	 * Resolves a lesson's owning course. LD has stored the link three different ways
	 * across versions: inside the `_sfwd-lessons['lesson_course']` serialized field,
	 * as a standalone `course_id` post meta, and via WordPress post hierarchy. Try each.
	 */
	private function resolve_lesson_course( int $lesson_post_id, array $lesson_settings ): int {
		$course_id = (int) ( $lesson_settings['lesson_course'] ?? 0 );
		if ( $course_id ) {
			return $course_id;
		}
		$course_id = (int) get_post_meta( $lesson_post_id, 'course_id', true );
		if ( $course_id ) {
			return $course_id;
		}
		return (int) wp_get_post_parent_id( $lesson_post_id );
	}

	/**
	 * Resolves a topic's owning course. Topics live under a parent lesson which lives under
	 * a course; modern LD also stores `course_id` directly on the topic. Try the direct
	 * meta, then the parent lesson's resolution, then the post-hierarchy walk.
	 */
	private function resolve_topic_course( int $topic_post_id, int $parent_lesson_id ): int {
		$course_id = (int) get_post_meta( $topic_post_id, 'course_id', true );
		if ( $course_id ) {
			return $course_id;
		}

		if ( $parent_lesson_id ) {
			$course_id = $this->resolve_lesson_course(
				$parent_lesson_id,
				$this->settings_array( $parent_lesson_id, self::META_LESSON_SETTINGS )
			);
			if ( $course_id ) {
				return $course_id;
			}
		}

		$lesson_id = (int) wp_get_post_parent_id( $topic_post_id );
		if ( $lesson_id ) {
			return (int) wp_get_post_parent_id( $lesson_id );
		}
		return 0;
	}

	private function settings_array( int $post_id, string $meta_key ): array {
		$value = get_post_meta( $post_id, $meta_key, true );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Returns the pass mark on a 0-100 scale. `passingpercentage` is already 0-100;
	 * `threshold` is stored 0-1 and is scaled up.
	 */
	private function normalize_passmark( array $settings ): float {
		if ( isset( $settings['passingpercentage'] ) ) {
			return (float) $settings['passingpercentage'];
		}
		if ( isset( $settings['threshold'] ) ) {
			return (float) $settings['threshold'] * 100;
		}
		return 0.0;
	}

	/**
	 * Coerces a mixed value (array, comma-separated string, or empty) into a list of ints.
	 */
	private function normalize_int_list( $value ): array {
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'intval', $value ) ) );
		}
		if ( is_string( $value ) && '' !== $value ) {
			return array_values( array_filter( array_map( 'intval', explode( ',', $value ) ) ) );
		}
		return array();
	}

	/**
	 * Sums counts across every post status returned by `wp_count_posts()`,
	 * including drafts and trash.
	 */
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
	 * the raw string through. `b:0;` is a valid serialized boolean false and would
	 * otherwise be indistinguishable from an unserialize failure.
	 */
	private function maybe_unserialize( string $value ) {
		if ( '' === $value ) {
			return null;
		}
		if ( is_serialized( $value ) ) {
			if ( 'b:0;' === $value ) {
				return false;
			}
			$unserialized = unserialize( $value, array( 'allowed_classes' => false ) );
			if ( false !== $unserialized ) {
				return $this->normalize_unserialized( $unserialized );
			}
		}
		$decoded = json_decode( $value, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			return $decoded;
		}
		return $value;
	}

	/**
	 * Recursively converts `__PHP_Incomplete_Class` placeholders into plain associative
	 * arrays. WP-Pro-Quiz serializes its model objects (e.g. `WpProQuiz_Model_AnswerTypes`),
	 * but we unserialize with `allowed_classes => false` for safety, so every object comes
	 * back as a ghost. The data is intact on protected/private properties, just hidden
	 * behind null-byte-prefixed keys; this strips the prefixes and yields a usable shape.
	 */
	private function normalize_unserialized( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'normalize_unserialized' ), $value );
		}
		if ( $value instanceof \__PHP_Incomplete_Class ) {
			$props = (array) $value;
			unset( $props['__PHP_Incomplete_Class_Name'] );
			$clean = array();
			foreach ( $props as $key => $prop_value ) {
				$clean_key           = preg_replace( '/^\0(?:\*|[^\0]+)\0/', '', (string) $key );
				$clean[ $clean_key ] = $this->normalize_unserialized( $prop_value );
			}
			return $clean;
		}
		return $value;
	}
}
