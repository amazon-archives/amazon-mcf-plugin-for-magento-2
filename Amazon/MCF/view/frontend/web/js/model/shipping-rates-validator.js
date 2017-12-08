/*
 * Copyright 2017 Amazon.com, Inc. or its affiliates. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License").
 * You may not use this file except in compliance with the License.
 * A copy of the License is located at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * or in the "license" file accompanying this file. This file is distributed
 * on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either
 * express or implied. See the License for the specific language governing
 * permissions and limitations under the License.
 *
 */

define(
    [
      'jquery',
      'mageUtils',
      './shipping-rates-validation-rules',
      'mage/translate'
    ],
    function ($, utils, validationRules, $t) {
      'use strict';
      var checkoutConfig = window.checkoutConfig;

      return {
        validationErrors: [],
        validate: function (address) {
          var rules = validationRules.getRules(),
              self = this;

          $.each(rules, function (field, rule) {
            if (rule.required && utils.isEmpty(address[field])) {
              var message = $t('Field ') + field + $t(' is required.');
              self.validationErrors.push(message);
            }
          });

          if (!Boolean(this.validationErrors.length)) {
            if (address.country_id == checkoutConfig.originCountryCode) {
              return !utils.isEmpty(address.postcode);
            }
            return true;
          }
          return false;
        }
      };
    }
);