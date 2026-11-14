# Speed on a very large mailbox

`measure-big-mailbox.js` times the main actions of the webmail (open, pages,
sort, conversations, searches, filters) against `tests/e2e/fake_ews.py` loaded
with a very large mailbox and the latency of a hosted Exchange:

```bash
python3 tests/e2e/fake_ews.py --port 8443 --cert cert.pem --key key.pem \
  --bulk 200000 --bulk-folders 300 --latency 150 --timing /tmp/ews-timing.tsv
TIMING=/tmp/ews-timing.tsv OUT=/tmp/perf.json node tests/perf/measure-big-mailbox.js
```

Each line gives the time waited (median of 3), the Exchange requests made, the
messages they returned, and the time the fake server spent itself (a real
Exchange answers from its indexes: leave it out).

The Nextcloud is set up as for `tests/e2e`, without a webmail account yet.
