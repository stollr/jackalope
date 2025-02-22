CHANGELOG
=========

2.x
===

2.0.0 (unreleased)
------------------

* Switch to strict static typing.
* Fixed incorrect return type on `FullTextSearch::getFullTextSearchExpression` to return a QOM Literal instead of `string`, as per the PHPCR spec.
* Drop support for PHP 7.

1.x
===

1.4.6
-----

* Fix dynamically declared variables
* Add ReturnTypeWillChange to avoid PHP warnings.

1.4.5
-----

* Fix more deprecations for PHP 8.1

1.4.4
-----

* Fix some more deprecations about ReturnTypeWillChange.

1.4.3
-----

* Fix PHP 8.1 deprecation

1.4.2
-----

* Support PHPUnit 9

1.4.1
-----

* PHP 8.0 support

1.4.0
-----

* PHP 7.4 support
* dropped PHP 5 and 7.0 support

1.3.7
-----

* bugfix #353 revert side effect from #351 with query result rows

1.3.6
-----

* dropped HHVM support because it started failing
* bugfix #351 fix getting primary type from a query result row.
* bugfix #351 fix handling of boolean in import/export.

1.3.5
-----

* bugfix #347 make version labels work.

1.3.4
-----

* PHP 7.2 support.

1.3.3
-----

* bugfix #333 avoid running out of memory in error report when using var_export.

1.3.2
-----

* bufix #335 fix edge case of #332 when we can't get the property value.

1.3.1
-----

* bugfix #332 return early from Node::setProperty when value does not change. This avoids regressions with #307.

1.3.0
-----

* feature PHP 7 support.
* bugfix #329 pick most specific property definition on multiple wildcards.
* bugfix #323 Empty array properties no longer break queries.
* feature #307 register UUID immediately so that getNodeByIdentifier works even before saving the session. 
* feature #302 Added methods addVersionLabel and removeVersionLabel to VersioningInterface.
* feature #245 Added NodeProcessor class which can be used by implementations to validate and process nodes.
* bugfix #229 Userland node type filtering does not work.
