/*
   +----------------------------------------------------------------------+
   | Copyright © The PHP Group and Contributors.                          |
   +----------------------------------------------------------------------+
   | This source file is subject to the Modified BSD License that is      |
   | bundled with this package in the file LICENSE, and is available      |
   | through the World Wide Web at <https://www.php.net/license/>.        |
   |                                                                      |
   | SPDX-License-Identifier: BSD-3-Clause                                |
   +----------------------------------------------------------------------+
*/

#include "php.h"
#include "SAPI.h"
#include "rfc1867.h"
#include "ext/json/php_json.h"

#include "php_content_types.h"

/* {{{ php_post_entries[] */
static const sapi_post_entry php_post_entries[] = {
	{ DEFAULT_POST_CONTENT_TYPE, sizeof(DEFAULT_POST_CONTENT_TYPE)-1, sapi_read_standard_form_data,	php_std_post_handler },
	{ MULTIPART_CONTENT_TYPE,    sizeof(MULTIPART_CONTENT_TYPE)-1,    NULL,                         rfc1867_post_handler },
	{ JSON_POST_CONTENT_TYPE,    sizeof(JSON_POST_CONTENT_TYPE)-1,    sapi_read_standard_form_data, php_json_post_handler },
	{ NULL, 0, NULL, NULL }
};
/* }}} */

/* {{{ SAPI_POST_HANDLER_FUNC */
SAPI_API SAPI_POST_HANDLER_FUNC(php_json_post_handler)
{
	zval *arr = (zval *) arg;
	php_stream *s = SG(request_info).request_body;
	zend_string *body;
	zval decoded;

	if (!s || php_stream_rewind(s) != SUCCESS) {
		return;
	}

	body = php_stream_copy_to_mem(s, PHP_STREAM_COPY_ALL, 0);
	if (!body) {
		return;
	}

	if (php_json_decode_ex(&decoded, ZSTR_VAL(body), ZSTR_LEN(body),
			PHP_JSON_OBJECT_AS_ARRAY, PHP_JSON_PARSER_DEFAULT_DEPTH) == SUCCESS) {
		if (Z_TYPE(decoded) == IS_ARRAY) {
			zend_string *key;
			zend_ulong idx;
			zval *val;

			/* Copy the top-level pairs into the $_POST array. Scalar
			 * top-level values leave $_POST empty, and invalid JSON
			 * degrades like unparsable form data: empty $_POST, raw body
			 * still available via php://input. */
			ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL(decoded), idx, key, val) {
				Z_TRY_ADDREF_P(val);
				if (key) {
					zend_symtable_update(Z_ARRVAL_P(arr), key, val);
				} else {
					zend_hash_index_update(Z_ARRVAL_P(arr), idx, val);
				}
			} ZEND_HASH_FOREACH_END();
		}
		zval_ptr_dtor(&decoded);
	}

	zend_string_release_ex(body, 0);
}
/* }}} */

/* {{{ SAPI_POST_READER_FUNC */
SAPI_API SAPI_POST_READER_FUNC(php_default_post_reader)
{
	if (!strcmp(SG(request_info).request_method, "POST")) {
		if (NULL == SG(request_info).post_entry) {
			/* no post handler registered, so we just swallow the data */
			sapi_read_standard_form_data();
		}
	}
}
/* }}} */

/* {{{ php_startup_sapi_content_types */
int php_startup_sapi_content_types(void)
{
	sapi_register_default_post_reader(php_default_post_reader);
	sapi_register_treat_data(php_default_treat_data);
	sapi_register_input_filter(php_default_input_filter, NULL);
	return SUCCESS;
}
/* }}} */

/* {{{ php_setup_sapi_content_types */
int php_setup_sapi_content_types(void)
{
	sapi_register_post_entries(php_post_entries);

	return SUCCESS;
}
/* }}} */
