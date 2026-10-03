"""Travel client boundary tests against localhost HTTP; server PHP/DB enforcement is separate."""
import json
import unittest
import uuid
import test_client as tc
from qaredi_mcp.client import QarediClient, QarediError


class TravelClient(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        tc.ClientTest.setUpClass()

    @classmethod
    def tearDownClass(cls):
        tc.ClientTest.tearDownClass()

    def setUp(self):
        tc.Fake.hits = []
        tc.Fake.mode = {}
        self.c = QarediClient(tc.ClientTest.base, tc.TOKEN, timeout=5)
        self.path = '/community/api/travel-plans'

    def test_create_new_private_plan_and_stable_activity_ids(self):
        tc.Fake.mode[self.path] = (201, {'plan': {'id': tc.PAGE_ID, 'visibility': 'private'}})
        out = self.c.save_travel_plan({'title': '여행', 'steps': [{'title': '산책', 'date': None}]}, request_id=tc.PAGE_ID2)
        self.assertEqual(out['plan']['visibility'], 'private')
        method, path, headers, raw = tc.Fake.hits[-1]
        body = json.loads(raw)
        self.assertEqual(method, 'POST')
        self.assertEqual(path, self.path)
        self.assertEqual(headers['x-api-token'], tc.TOKEN)
        self.assertEqual(body['request_id'], tc.PAGE_ID2)
        self.assertEqual(str(uuid.UUID(body['steps'][0]['id'])), body['steps'][0]['id'])
        self.assertNotIn('visibility', body)

    def test_update_requires_revision_and_keeps_supplied_step_id(self):
        tc.Fake.mode[self.path + '/' + tc.PAGE_ID] = (200, {'plan': {'revision': 3}})
        plan = {'title': '바뀐 여행', 'steps': [{'id': tc.PAGE_ID2, 'title': '하루 뒤 산책'}]}
        self.c.save_travel_plan(plan, tc.PAGE_ID, 2)
        method, _, _, raw = tc.Fake.hits[-1]
        self.assertEqual(method, 'PATCH')
        self.assertEqual(json.loads(raw)['revision'], 2)
        self.assertEqual(json.loads(raw)['steps'][0]['id'], tc.PAGE_ID2)
        for revision in (None, True, 0, '2'):
            with self.assertRaises(QarediError):
                self.c.save_travel_plan(plan, tc.PAGE_ID, revision)

    def test_foreign_fields_cannot_change_owner_or_visibility(self):
        for field in ('owner_user_id', 'visibility', 'deleted_at', 'caption'):
            with self.assertRaises(QarediError):
                self.c.save_travel_plan({'title': '여행', field: 'x'})
        self.assertEqual(tc.Fake.hits, [])

    def test_invalid_ids_never_reach_network(self):
        for value in ('../pages', tc.PAGE_ID + '?token=x', 'https://example.org/' + tc.PAGE_ID):
            with self.assertRaises(QarediError): self.c.get_travel_plan(value)
            with self.assertRaises(QarediError): self.c.link_travel_record(tc.PAGE_ID, value)
        self.assertEqual(tc.Fake.hits, [])

    def test_record_link_allows_unplanned_moment_without_publication(self):
        path = self.path + '/' + tc.PAGE_ID + '/records'
        tc.Fake.mode[path] = (200, {'plan': {'id': tc.PAGE_ID}})
        self.c.link_travel_record(tc.PAGE_ID, tc.PAGE_ID2)
        method, _, _, raw = tc.Fake.hits[-1]
        self.assertEqual(method, 'POST')
        self.assertEqual(json.loads(raw), {'post_id': tc.PAGE_ID2, 'step_id': None})

    def test_scope_failure_explains_dedicated_scope(self):
        tc.Fake.mode[self.path] = (403, {'error': 'token_scope'})
        with self.assertRaises(QarediError) as cm: self.c.list_travel_plans()
        self.assertIn('travel.read', str(cm.exception))
        self.assertNotIn(tc.TOKEN, str(cm.exception))
        self.assertEqual(len(tc.Fake.hits), 1)

    def test_revision_conflict_never_auto_overwrites(self):
        tc.Fake.mode[self.path + '/' + tc.PAGE_ID] = (409, {'error': 'conflict'})
        with self.assertRaises(QarediError) as cm: self.c.save_travel_plan({'title': '여행'}, tc.PAGE_ID, 1)
        self.assertIn('revision', str(cm.exception))
        self.assertEqual(len(tc.Fake.hits), 1)

    def test_list_and_read_only_use_fixed_routes(self):
        tc.Fake.mode[self.path] = (200, {'plans': []})
        tc.Fake.mode[self.path + '/' + tc.PAGE_ID] = (200, {'plan': {'id': tc.PAGE_ID}})
        self.c.list_travel_plans(); self.c.get_travel_plan(tc.PAGE_ID)
        self.assertEqual([(h[0], h[1]) for h in tc.Fake.hits], [('GET', self.path), ('GET', self.path + '/' + tc.PAGE_ID)])

    def test_limits_fail_before_network(self):
        with self.assertRaises(QarediError): self.c.save_travel_plan({'title': '여행', 'steps': [{}] * 101})
        with self.assertRaises(QarediError): self.c.save_travel_plan({'title': '여행', 'notes': '가' * 90000})
        self.assertEqual(tc.Fake.hits, [])
