Databases do not provide a robust enough means of describing in specific terms the data that should be stored in it. Utilizing JSON stored in each column's COMMENT attribute (a typically unused attribute), we are able to define more complex validation and allow a single class to handle all data validation.

The JSON stored in the COMMENT must be minified (no spaces, short names) due to length restrictions on the COMMENT, but here is an un-minified example:
```javascript
'{
    "insert_helpers": {
            "functions": {
                    "func1":{
                            "name":"strtotime",
                            "params":{
                                    "param1":"+20 years"
                            }
                    },
                    "func2":{
                            "name":"str_replace",
                            "params":{
                                    "param1":"!!",
                                    "param2":"!",
                                    "param3":"@this",
                            }
                    }
            }
    },
    "validators": {
            "maxlength":"10",
            "minlength":"2",
            "patterns":{
                    "pattern1":{
                            "pattern":"[^0-9]",
                            "example":"This is data without numbers"
                    }
            }
    }
}'
```