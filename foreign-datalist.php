<?php
/**
 * IMPORTANT: Please, activate struct-comments.php plugin before continue.
 * It is necessary because this plugin needs to read the field's comment in html interface.
 *
 * USAGE EXAMPLE:
 *
 * Lets say you have a field called EMPLOYEE_ID in the table ORDERS and you want fill it with
 *   employee's ID by typing any part of it's name or phone number that are stored in another table.
 *   The list must show only ACTIVE employees and return a maximum of 999 rows.
 *
 * Alter the structure of ORDERS table and insert the string below in the EMPLOYEE_ID comment:
 *
 * "dropdown:{table:HR.EMPLOYEES, label:[FULL_NAME, PHONE], value:ID, filter:ACTIVE, limit:999}"
 *
 * filter and limit are optional, where:
 *   filter: will add a "WHERE ACTIVE = '1'" in sql query
 *   limit: will overwrite the default value (10000)
 *
 * Do NOT put any parameter inside Quotation Marks (").
 *
 * In the EDIT interface, the user will click on EMPLOYEE_ID and start typing.
 * The click will start the plugin that reads the comment, make an ajax call, read the HR.EMPLOYEES
 *   table, return a json with results (select2.org format) and attach then as a datalist.
 *
 * The main structure of this plugin was taken from
 * https://github.com/derStephan/AdminerPlugins/blob/master/searchAutocomplete.php thanks!
 *
 * This approach has some advantages:
 *  No ajax call is make, unless user CLICK on the field.
 *  Results are cached on user's browser until the page is reload (field became lightgreen to indicate)
 *  The user has the free to change
 *  The user is free to input any value, even if not in the list
 *
 * Tested with php 7.0~8.3 / mysql 5.7~8.0
 *
 * @author Marcelo Gennari, https://gren.com.br/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License, version 2 (one or other)
 * @version 2.0.1
 */
class AdminerForeignDatalist {
	/** @access protected */
	var $placeholder, $limit;

	/**
	* @param string placeholder: Placeholder applied to the field.
	* @param int limit: Max datalist size. Defaults to 10000.
	*/

	function __construct(
			$placeholder = 'Click and type keydown to show options'
			, $limit = 10000
			) {
			$this->placeholder = $placeholder;
			$this->limit = $limit;
	}

	// answer ajax requests with json
	public function headers() {
		if(isset($_POST["foreignDatalistGren"])) { // ajax call?
			try {
				set_time_limit(5);
				$payload = json_decode($_POST["foreignDatalistGren"]);
				// $dbTableFieldname = preg_replace("/[^a-zA-Z0-9._~-]/", "", $payload->jsonFromComment->table);
				unset($_POST["foreignDatalistGren"]);
				// prepare values from payload
				$labels = (gettype($payload->jsonFromComment->labels) == 'array') ? $payload->jsonFromComment->labels : [$payload->jsonFromComment->labels];
				$value = [$payload->jsonFromComment->value]; // array
				$filter = isset($payload->jsonFromComment->filter) ? $payload->jsonFromComment->filter : '';
				$limit = (isset($payload->jsonFromComment->limit) && preg_match('~^\d{1,5}$~', $payload->jsonFromComment->limit))
						? $payload->jsonFromComment->limit
						: $this->limit;
				// build query
				$select = 'SELECT ' . implode(", ", array_unique(array_merge($labels, $value)));
				$from = ' FROM ' . $payload->jsonFromComment->table;
				$where = $filter ? " WHERE $filter = '1'" : '';
				$limit = " LIMIT $limit";
				$query = $select . $from . $where . $limit . ';';
				// Submit the query and save results into an select2 compatible object
				$connection = connection(); $output = (object) array('results' => array());
				$resultset = $connection->query($query, 1);
				if ($resultset) {
					while ($row = $resultset->fetch_assoc()) {
						$output->results[] = (object) ['id' => ($row[$value[0]] ? $row[$value[0]] : ''), 'text' => implode(", ", array_intersect_key($row, array_flip($labels)))];
					}
				} else {
					throw new Exception("No results: ($query)");
				}
			} catch (Exception $ex) {
				$output->results[] = (object) [ 'id' => 'erro', 'text' => $ex->getMessage() ];
			}
			echo json_encode($output);
			die(); // stop
		}
	}

	public function head() {
		// INICIAL CHECK
		// interface must be 'edit'
		if (! isset($_GET['edit']) ) { return; }
		// global $fields can't be null
		global $fields;
		if (! $fields ) { return; }
		// AdminerStructComments is loaded
		global $adminer; $regex = '~AdminerStructComments.*~';
		$hasStructCommentsPlugin = array_filter($adminer->plugins, function($item) use ($regex) { return preg_match($regex, get_class($item)); });
		if (! $hasStructCommentsPlugin ) { echo script("alert('" . get_class($this) . " depends on AdminerStructComments.')"); return; }
		// at least one "dropdownable" field
		$dropDownableFields = [];
		foreach ($fields as $field) { if ( preg_match("~dropdown *: *\{.+\}~", $field['comment'] )) { $dropDownableFields[] = $field['field']; } }
		if (! $dropDownableFields ) { return; }
		// ALL ABOVE IS OK, PROCEED
		$dropDownableFieldsJs = '[' . implode(", ", array_map(function($item) { return "'$item'"; }, $dropDownableFields)) . ']';
		?>

		<script <?php echo nonce()?> type='text/javascript'>
		// attach mousedown listeners
		document.addEventListener('DOMContentLoaded', function() {
			let dropDownableFields = <?php echo $dropDownableFieldsJs ?>.map(item => `fields[${item}]`);
			dropDownableFields.forEach(item => {
				let dropDownableField = document.getElementsByName(item)[0];
				dropDownableField.addEventListener('mousedown', populateAutocompleteDataList);
				dropDownableField.placeholder = `<?php echo $this->placeholder ?>`
				// make field bigger and remove only-numbers constraint
				if (dropDownableField.getAttribute('type') == 'number') { dropDownableField.setAttribute('size', '40'); dropDownableField.removeAttribute('type'); }
			});
		});
		function populateAutocompleteDataList(ev) {
			// ASSEMBLE PAYLOAD
			let fieldValue = ev.target.value.trim();
			let jsonFromComment = extractJsonFromComment(ev.target.closest("tr").querySelector("[title]").getAttribute("title"));
			let postPayload = JSON.stringify({'fieldValue': fieldValue, 'jsonFromComment': JSON.parse(jsonFromComment)});
			// CREATE DATALIST OBJECT
			let dropDownId = ev.target["name"].match(/fields\[(.+)\]/)[1] + '_dropdown';
			if ( ! document.getElementById(dropDownId) ) {
				let dataList = document.createElement('datalist');
				dataList.setAttribute('id', dropDownId);
				ev.target.parentElement.append(dataList);
			}
			// VERIFY IF DATALIST ALREADY EXISTS
			if ( ev.target.getAttribute('list') == dropDownId ) {
				return; // quit
			}
			// FILL DATALIST WITH AJAX-RETURNED VALUES
			// clear datalist
			var dataList = document.getElementById(dropDownId);
			dataList.innerHTML = '';
			// send ajax
			var ajax = new XMLHttpRequest();
			ajax.open('POST', '', true);
			ajax.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
			ajax.send('foreignDatalistGren=' + postPayload);
			// fill datalist with ajax returned json
			ajax.onreadystatechange = function() {
				if (this.readyState == 4 && this.status == 200) {
					var response = JSON.parse(this.responseText);
					ev.target.setAttribute('autocomplete', 'off'); // clear autocomplete
					response.results.forEach(function(item) {
						var option = document.createElement('option'); // temp obj
						option.value = item.id; option.label = item.text;
						dataList.appendChild(option);
						ev.target.style.backgroundColor = "#f6ffe9"; // light green to indicate resuts cached
						ev.target.setAttribute('list', dropDownId); // attach the datalist
					});
				}
			}
		}
		function extractJsonFromComment(comment) {
			let extracted = comment.match(/dropdown *: *(\{.+\})/i)[1].replace(/["']/g, '');
			let $return = extracted.replace(/[\w\-._]+/g, match => `"${match}"`);
			return $return;
		}
		</script>

		<?php
	}

}