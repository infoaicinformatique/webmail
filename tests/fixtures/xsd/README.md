Exchange Web Services schema, version `Exchange2013_SP1` (the version the client
announces in `RequestServerVersion`). Copied from
https://github.com/gautamsi/ews-javascript-api/tree/master/src/js/SchemaXML ; the only
change is a local `schemaLocation` for the `xml:` namespace import (`xml.xsd`) so that
validation works offline. Used by `tests/unit/EwsClientTest.php`.
