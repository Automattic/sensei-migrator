<?php
/**
 * Contract every LMS source must implement.
 *
 * @package Sensei_Migrator
 */

namespace Sensei_Migrator\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Each method returning records returns an iterable of associative arrays.
 *
 * Record shapes are documented in includes/core/record-shapes.md and validated by
 * Sensei_Migrator\Core\Record_Validator. Adapters are responsible for normalizing
 * source-specific quirks before yielding.
 */
interface Source_Adapter {

	/**
	 * Stable machine identifier (e.g. "learndash"). Used by `wp sensei migrate --from=<slug>`.
	 */
	public function slug(): string;

	/**
	 * Human-readable label for admin UI (e.g. "LearnDash").
	 */
	public function label(): string;

	/**
	 * True when the source plugin/data is detected on this site. Adapters that cannot
	 * detect their source (e.g. file-based adapters) return true unconditionally and
	 * defer presence checks to inventory().
	 */
	public function detect(): bool;

	/**
	 * Counts of available source content. Drives the admin UI inventory view and the
	 * --dry-run output.
	 *
	 * @return array{
	 *     courses:int,
	 *     lessons:int,
	 *     quizzes:int,
	 *     questions:int,
	 *     enrollments:int,
	 * }
	 */
	public function inventory(): array;

	/**
	 * @return iterable<array> Course records.
	 */
	public function read_courses(): iterable;

	/**
	 * @return iterable<array> Lesson records (including any flattened sub-lesson concepts).
	 */
	public function read_lessons(): iterable;

	/**
	 * @return iterable<array> Quiz records, one per lesson that has a quiz.
	 */
	public function read_quizzes(): iterable;

	/**
	 * @return iterable<array> Question records. May be referenced by multiple quizzes.
	 */
	public function read_questions(): iterable;

	/**
	 * @return iterable<array> Enrollment records: (user, course) pairs.
	 */
	public function read_enrollments(): iterable;
}
