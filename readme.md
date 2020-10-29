# UI Patterns for Miyagi

This module helps integrating the miyagi pattern library with your drupal installation.

## What is miyagi

miyagi is a component development tool for JavaScript templating engines. More info [here](https://docs.miyagi.dev/)

## Installation

Install the module as usual, all dependencies are handled by composer.

## Dependencies

*  `ui_patterns_library`
*  `components`

## Usage

There is nothing special. The module registers every found miyagi-pattern found in
twig namespaces exposed by the components-module. You can view a list of patterns
at `/patterns`. Please have a look at the UI-Patterns documentation on how to work
with UI Patterns.

## Requirements/ Limitations

* The implementation requires that your miyagi pattern has a schema. The module supports
  all other files if available like info, mocks and readmes.
* The current implementation supports only yaml-files for structured data like schema, mocks
  or info-files
* The folder name of the pattern is transformed into its id. So pattern `elements/image`
  is available under the ID `elements_image`
* The concept of variants in miyagi and in UI Patterns are not compatible, miyagis variants
  are only supported for mock-data.
* Only the active theme is currently supported
* Mixing this module with other library-modules like ui_patterns_patternlab might lead to errors
  and is not tested.

## Input validation

As miyagi patterns define a JSON-schema you can instruct the module to validate
pattern input against this schema. Add the following line to your `settings.php` and
miyagi willl throw an exception when the validation fails:

```
$settings['miyagi_validate_input'] = TRUE;
```


