<?php
$config = [
	'xplan' => ['url' => 'https://amp.xplan.iress.com.au/RPC2/', 'username' => 'jhayes3', 'password' => 'Welcome3'],
	'mysql' => ['host' => 'localhost', 'username' => 'root', 'password' => '', 'database' => 'jdem01']
];

function message($message) {
	echo $message;		// generate email or whatever
	exit();				// kill script
}

function processXMLResponse($response) {
	$decoded = xmlrpc_decode($response);
	if (is_array($decoded) && xmlrpc_is_fault($decoded)) {
		message("Decoded XML Response Error: <b>{$decoded['faultString']} ({$decoded['faultCode']})</b>");
	}
	return $decoded;
}

// CONNECT
if (!$ch = curl_init($config['xplan']['url'])) { // generate curl handle
	message("CURL Connection Error: <b>Check url</b>");
}

// LOGIN
$postfields = xmlrpc_encode_request('edai.Login', [$config['xplan']['username'], $config['xplan']['password']]);  // package as xml
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/xml'));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);      // not advised, I need to find out how to avoid this
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);      // not advised, I need to find out how to avoid this
if (!$xmlresponse = curl_exec($ch)) {
	message("CURL Login Error: <b>" . curl_error($ch) . "</b>");
}
$token = processXMLResponse($xmlresponse);
if (!preg_match("~^[\w+]{20}$~", $token)) {
	message("Invalid/Unexpected Token Generated: <b>$token</b>");
}

// SEARCH XPLAN
$basepath = 'entitymgr/client';
$queryxml = <<<XML
<EntitySearch>
<SearchResult field="entity_id"/>
<SearchResult field="preferred_email"/>
</EntitySearch>
XML;
$request = xmlrpc_encode_request("edai.Search", [$token, $basepath, $queryxml]);  // this mutates the nested $queryxml string
curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
if (!$xmlresponse = curl_exec($ch)) {
	message("CURL Search Error: <b>" . curl_error($ch) . "</b>");
}
$xml = processXMLResponse($xmlresponse);

// CONVERT XML RESPONSE TO MULTIDIMENSIONAL ARRAY
$dom = new DOMDocument;
$dom->loadXML($xml);
if (!$count = $dom->documentElement->getAttribute('count')) {
	message("Empty XML Response: <b>Investigate why there are no rows</b>");
}
foreach ($dom->documentElement->childNodes as $set) {
	$id = $set->getAttribute('name');
	foreach ($set->childNodes[0]->childNodes as $node) {
		$results[$id][] = trim(strip_tags($node->nodeValue)); // $id also equals: trim($node->getAttribute('name'))
	}
}
if (!isset($results)) {
	message("No Generated Multidimensional Array: <b>Investigate XML parsing failed</b>"); // <pre>" . html_entity_decode($xml) . "</pre>"
}

curl_close($ch);

// UPDATE MYSQL TABLE DATA	
if (!$conn = new mysqli($config['mysql']['host'], $config['mysql']['username'], $config['mysql']['password'], $config['mysql']['database'])) {
	message("MySQL Connection Error: <b>Check config values</b>");  // $conn->connect_error
}
if (!$result = $conn->query("UPDATE siwqt_fields_values SET value = 'not set' WHERE field_id = 19 AND value <> 'not set'")) {
	message("MySQL Query Syntax Error: <b>Failed to UPDATE all rows to 'not set'<b>");  // $conn->error
}
/*if (!$conn->affected_rows) {
	message("MySQL Query Logic Error: <b>Failed to UPDATE any rows to 'not set'</b>");
}*/

$count = sizeof($results);
$subselect_unions = str_repeat(" UNION SELECT ?, ?", $count -1);  // subsequent rows of a derived table do not require aliases
$sql = <<<SQL
UPDATE siwqt_fields_values a
JOIN
	(
        SELECT item_id as joomla_id, value AS xplan_id
        FROM siwqt_fields_values
        WHERE field_id = 1
	) b ON a.item_id = b.joomla_id
JOIN
	(
		SELECT ? AS xplan_id, ? AS new_email$subselect_unions
	) c ON b.xplan_id = c.xplan_id
SET a.value = c.new_email
WHERE a.field_id = 19
SQL;
$param_types = str_repeat('ss', $count);

// echo "<textarea cols='100' rows='10'>$sql</textarea>";
	
// PREPARE, BIND, EXECUTE, COUNT
if (!$stmt = $conn->prepare($sql)) {
	message("MySQL Query Syntax Error: <b>Failed to prepare query</b>");  // $conn->error;
}
if (!$stmt->bind_param($param_types, ...array_merge(...$results))) {  // array_merge(...$results) flattens; ... unpacks
	message("MySQL Query Syntax Error: <b>Failed to bind placeholders and data</b>");  // $stmt->error;
}
if (!$stmt->execute()) {
	message("MySQL Query Syntax Error: <b>Execution of prepared statement failed.</b>");  // $stmt->error;
}

message("<b>Joomla client emails are up-to-date<b>. (Affected rows in update: <b>$stmt->affected_rows</b>)");