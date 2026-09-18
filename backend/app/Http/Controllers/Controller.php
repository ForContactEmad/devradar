<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * The base controller.
 *
 * THIS FILE WAS MISSING. Six controllers declared `extends Controller` and
 * nothing defined it, so every route in the application failed with
 * "Class App\Http\Controllers\Controller not found" on the first real request.
 * Nothing caught it earlier because the HTTP layer had never executed: the
 * unit suite tests the query services beneath the controllers, never the
 * controllers themselves.
 *
 * DELIBERATELY EMPTY. In Laravel 11 and 12 the base controller no longer
 * extends a framework class, and the AuthorizesRequests and ValidatesRequests
 * traits are opt-in rather than inherited by default.
 *
 * DevRadar needs neither:
 *
 *   - authorisation lives in middleware (RequireAdminToken), not in
 *     controller-level gates, because the public API has no per-resource
 *     permissions to check
 *   - validation lives in form requests (ProjectIndexRequest), which is where
 *     the rules can be shared between HTTP and the console commands
 *
 * Adding the traits here would hand every controller two entry points into
 * behaviour that belongs elsewhere in this architecture. The class exists to
 * give the controllers a common ancestor, and nothing more.
 */
abstract class Controller
{
}
