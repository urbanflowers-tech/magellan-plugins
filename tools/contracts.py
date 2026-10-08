"""Generate the reviewable v3.0.0 wire contracts and cross-language fixtures."""
import json, hashlib, hmac
from pathlib import Path
root = Path(__file__).resolve().parents[1] / 'contracts/v3'
def obj(props, required=None):
    return dict(type='object', properties=props, required=list(props) if required is None else required, additionalProperties=False)
text = dict(type='string', maxLength=256)
nullable = dict(type=['string','null'], maxLength=256)
uuid = dict(type='string', format='uuid')
date = dict(type='string', format='date-time')
money = obj(dict(currency=dict(type='string',pattern='^[A-Z]{3}$'),exponent=dict(type='integer',minimum=0,maximum=6),amount_minor=dict(type='string',pattern='^-?(0|[1-9][0-9]*)$')))
state = dict(enum=['granted','denied','unknown','not_applicable'])
consent = obj(dict(analytics=state,advertising=state,email_marketing=state,sms_marketing=state,source=text,effective_at=date,policy_version=text,epoch=dict(type='integer',minimum=0),gpc=dict(type='boolean')))
source = obj(dict(click_id_types=dict(type='array',maxItems=7,items=dict(enum=['gclid','gbraid','wbraid','fbclid','msclkid','ttclid','twclid'])),utm=obj({k: dict(type='string',maxLength=128) for k in ['source','medium','campaign','content','term']},[]),click_ids=obj({k: dict(type='string',maxLength=200) for k in ['gclid','gbraid','wbraid','fbclid','msclkid','ttclid','twclid']},[]),referrer_domain=nullable))
page = dict(navigation_id=uuid,path=dict(type='string',maxLength=512),page_id=nullable,entry_type=dict(enum=['home','content','product','category','commercial_landing','utility','unknown']),classification_version=text,storefront=text,served_version=nullable,product_id=nullable,variation_id=nullable,source_evidence=source,entry=dict(type='object'),continuity=dict(enum=['shared','page_local']),session_started_at=date,session_aliases=dict(type='array',maxItems=10,items=uuid),stream_id=uuid)
line = obj(dict(line_id=text,refunded_line_id=nullable,product_id=text,variation_id=nullable,sku=dict(type='string',maxLength=128),quantity=dict(type='string',pattern='^-?[0-9]+(\\.[0-9]+)?$'),unit=dict(const='item'),subtotal=money,total=money,tax=money))
entity = obj(dict(source_system=dict(const='woocommerce'),type=text,id=text,revision=text))
amounts = obj({k: money for k in ['subtotal','discount','shipping','tax','total']})
order = dict(source_system=dict(const='woocommerce'),order_id=text,source_revision=text,status=text,created_at=dict(type=['string','null'],format='date-time'),modified_at=dict(type=['string','null'],format='date-time'),currency=dict(type='string',pattern='^[A-Z]{3}$'),exponent=dict(type='integer',minimum=0,maximum=6),amounts=amounts,lines=dict(type='array',maxItems=50,items=line),fees=dict(type='array',items=obj(dict(line_id=text,total=money,tax=money))),taxes=dict(type='array',items=obj(dict(line_id=text,rate_id=text,total=money,shipping=money))),payment_method=text,transaction_id=nullable,paid_at=dict(type=['string','null'],format='date-time'),checkout_context=dict(type='object'),native_attribution=dict(type='object'),page=dict(type='integer',minimum=1),pages=dict(type='integer',minimum=1),snapshot_id=uuid,channel_origin=dict(enum=['web_checkout','other','unknown']),subscriptions=dict(type='object'))
payloads = {
 'page_viewed':obj(page), 'product_viewed':obj(page),
 'checkout_started':obj(dict(page=page['path'],checkout_attempt_id=uuid,adapter=text,stage=text)),
 'checkout_stage_observed':obj(dict(page=page['path'],checkout_attempt_id=uuid,adapter=text,stage=text)),
 'checkout_error_observed':obj(dict(page=page['path'],checkout_attempt_id=uuid,adapter=text,stage=text,category=dict(enum=['coupon_rejected','validation_failed','payment_failed','technical_error','unknown']))),
 'interaction_observed':obj(dict(page=page['path'],component_id=text,action_id=text,served_version=nullable)),
 'consent_changed':obj(dict(previous_epoch=dict(type='integer',minimum=0))),
 'cart_snapshot':obj(dict(snapshot_id=uuid,page=dict(type='integer',minimum=1),pages=dict(type='integer',minimum=1),cart_id=uuid,revision=text,lifecycle=dict(const='open'),currency=text,exponent=dict(type='integer'),merchandise_total=money,items=dict(type='array',items=line,maxItems=100))),
 'cart_emptied':obj(dict(cart_id=uuid,revision=text,lifecycle=dict(const='empty'),reason=text)),
 'cart_converted':obj(dict(cart_id=uuid,order_id=text,lifecycle=dict(const='converted'))),
 'order_snapshot':obj(order),
 'order_status_changed':obj(dict(order_id=text,from_status=text,to_status=text,source_revision=text)),
 'payment_fact':obj(dict(order_id=text,payment_method=text,transaction_id=nullable,source_state=dict(const='woocommerce_payment_complete'),paid_at=dict(type=['string','null'],format='date-time'),settlement_verified=dict(const=False))),
 'refund_recorded':obj(dict(snapshot_id=uuid,page=dict(type='integer',minimum=1),pages=dict(type='integer',minimum=1),order_id=text,refund_id=text,source_revision=text,status=text,amount=money,created_at=dict(type=['string','null'],format='date-time'),lines=dict(type='array',items=line,maxItems=100),allocations_available=dict(type='boolean'),provider_refund_id=nullable)),
 'entity_changed':obj(dict(type=text,id=text,revision=text,path=dict(type='string',maxLength=512),previous_path=dict(type=['string','null'],maxLength=512))),
 'entity_deleted':obj(dict(type=text,id=text)),
 'health_report':obj(dict(plugin_version=text,queue=dict(type='object'),capabilities=dict(type='object'),tracking_started_at=dict(type=['string','null'],format='date-time'))),
 'privacy_erasure_requested':obj(dict(request_id=uuid,order_ids=dict(type='array',items=text,maxItems=50),visitor_ids=dict(type='array',items=uuid,maxItems=100),session_ids=dict(type='array',items=uuid,maxItems=100),scope=dict(const='analytics')))
}
browser = ['page_viewed','product_viewed','checkout_started','checkout_stage_observed','checkout_error_observed','interaction_observed','consent_changed']
base = dict(schema_version=dict(const='3.0.0'),plugin_version=text,event_id=uuid,event_type=dict(enum=list(payloads)),site_id=text,installation_id=text,environment=dict(enum=['production','test','development']),producer=dict(enum=['browser','wordpress']),evidence_class=dict(enum=['observation','source_fact']),occurred_at=date,captured_at=date,visitor_id=dict(type=['string','null'],format='uuid'),session_id=dict(type=['string','null'],format='uuid'),cart_id=dict(type=['string','null'],format='uuid'),entity=dict(anyOf=[entity,dict(type='null')]),origin_system=text,operation_id=nullable,causation_id=nullable,sequence=dict(type='integer',minimum=1),consent=consent,payload=dict(type='object'))
schema=obj(base);schema.update({'$schema':'https://json-schema.org/draft/2020-12/schema','$id':'https://magellan.app/contracts/v3/event.schema.json','oneOf':[dict(properties=dict(event_type=dict(const=k),payload=v,producer=dict(const='browser' if k in browser else 'wordpress'),evidence_class=dict(const='observation' if k in browser else 'source_fact'))) for k,v in payloads.items()]})
receipt=obj(dict(event_id=uuid,body_hash=dict(type='string',pattern='^[0-9a-f]{64}$'),status=dict(enum=['accepted','duplicate','retry','rejected','payload_conflict']),receipt_id=nullable,received_at=dict(type=['string','null'],format='date-time'),code=nullable))
for name,data in [('event.schema',schema),('receipts.schema',dict(**obj(dict(results=dict(type='array',maxItems=50,items=receipt)) ),**{'$schema':schema['$schema']}))]:
    (root/(name+'.json')).write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n')
vectors=[]
for target, body in [('/api/v1/wordpress/events','{"text":"ดอกไม้ 🌷"}'),('/api/v1/wordpress/receipts?a=1&a=2&cursor=a%20b',''),('/api/v1/wordpress/events','{"a": 1}')]:
    digest=hashlib.sha256(body.encode()).hexdigest(); signed='\n'.join(['v3','POST' if body else 'GET',target,'install_fixture','1791428400','11111111-1111-4111-8111-111111111111',digest]); key=bytes(range(32))
    import base64
    vectors.append(dict(method='POST' if body else 'GET',target=target,installation_id='install_fixture',timestamp=1791428400,nonce='11111111-1111-4111-8111-111111111111',body=body,key_b64=base64.b64encode(key).decode(),body_hash=digest,canonical=signed,signature=hmac.new(key,signed.encode(),hashlib.sha256).hexdigest()))
(root/'fixtures/signatures.json').write_text(json.dumps(vectors,ensure_ascii=False,indent=2)+'\n')
