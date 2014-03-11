Please note, this is fairly old and not maintained. :)

Relational databases do not provide a robust enough means of describing in specific terms the data that should be stored in a column. This task is always put into the logic layer to sanitize incoming data, frequently through form handling logic. Personally, I am tired of writing form handlers, it's tedious and redundant. So, I had the idea of utilizing JSON stored in each column's COMMENT attribute (a mostly unused attribute) to define more complex validation and allow a single class to handle all data validation.

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
