import json,copy
from pathlib import Path
from jsonschema import Draft202012Validator,FormatChecker
r=Path(__file__).resolve().parents[1]
schema=json.loads((r/'contracts/v3/event.schema.json').read_text())
Draft202012Validator.check_schema(schema)
validator=Draft202012Validator(schema,format_checker=FormatChecker())
events=json.loads((r/'tests/captured-events.json').read_text())+json.loads((r/'tests/browser-events.json').read_text())
if (r/'tests/custom-checkout-events.json').exists():
    events+=json.loads((r/'tests/custom-checkout-events.json').read_text())
if (r/'tests/pilot-browser-events.json').exists():
    events+=json.loads((r/'tests/pilot-browser-events.json').read_text())
if (r/'tests/review-events.json').exists():
    events+=json.loads((r/'tests/review-events.json').read_text())
if (r/'tests/large-landing-events.json').exists():
    events+=json.loads((r/'tests/large-landing-events.json').read_text())
by_type={}
for event in events:
    validator.validate(event);by_type.setdefault(event['event_type'],event)
(r/'contracts/v3/fixtures/valid-events.json').write_text(json.dumps(list(by_type.values()),ensure_ascii=False,indent=2)+'\n')
wrong_type=copy.deepcopy(by_type['page_viewed']);wrong_type['event_type']='order_snapshot';wrong_type['payload']={'total':99999}
unknown_field=copy.deepcopy(by_type['page_viewed']);unknown_field['unexpected']='not allowed'
float_money=copy.deepcopy(by_type['order_snapshot']);float_money['payload']['amounts']['total']['amount_minor']=1.23
invalid=[wrong_type,unknown_field,float_money]
assert all(not validator.is_valid(event) for event in invalid)
(r/'contracts/v3/fixtures/invalid-events.json').write_text(json.dumps(invalid,indent=2)+'\n')
(r/'tests/schema-results.json').write_text(json.dumps({'valid_events':len(events),'event_types':sorted(by_type),'invalid_fixtures_rejected':len(invalid),'pass':True},indent=2)+'\n')
print(f'{len(events)} captured events validated; {len(invalid)} invalid fixtures rejected')
