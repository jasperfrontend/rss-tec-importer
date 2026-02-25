"""Generate a binary GNU MO file from a PO file."""
import struct
import sys
import os


def unescape(s):
    result, i = [], 0
    while i < len(s):
        if s[i] == '\\' and i + 1 < len(s):
            c = s[i + 1]
            if   c == 'n':  result.append('\n')
            elif c == 't':  result.append('\t')
            elif c == 'r':  result.append('\r')
            elif c == '"':  result.append('"')
            elif c == '\\': result.append('\\')
            else:           result.append('\\'); result.append(c)
            i += 2
        else:
            result.append(s[i])
            i += 1
    return ''.join(result)


def parse_po(path):
    pairs = []
    cur_id = cur_str = None
    in_id = in_str = False

    def save():
        if cur_id is not None and cur_str is not None:
            pairs.append((cur_id, cur_str))

    with open(path, encoding='utf-8') as f:
        for line in f:
            line = line.rstrip('\r\n')
            if line.startswith('#') or not line.strip():
                continue
            if line.startswith('msgid '):
                save()
                cur_id  = unescape(line[7:-1])
                cur_str = None
                in_id   = True
                in_str  = False
            elif line.startswith('msgstr '):
                cur_str = unescape(line[8:-1])
                in_id   = False
                in_str  = True
            elif line.startswith('"') and line.endswith('"'):
                val = unescape(line[1:-1])
                if in_id:    cur_id  += val
                elif in_str: cur_str += val
    save()
    return pairs


def make_mo(pairs, out_path):
    # Only keep entries that have a non-empty translation (or are the header).
    pairs = [(k, v) for k, v in pairs if v or k == '']
    # Sort by original string bytes (required for binary search in libintl).
    pairs.sort(key=lambda x: x[0].encode('utf-8'))

    N = len(pairs)
    O = 28          # offset of original-strings table
    T = O + N * 8   # offset of translated-strings table
    S = 0           # hash table size (we omit it)
    H = T + N * 8   # offset of hash table (= start of string data here)

    # Compute absolute file offsets for every string.
    orig_meta  = []
    pos = H
    for k, _ in pairs:
        b = k.encode('utf-8')
        orig_meta.append((len(b), pos))
        pos += len(b) + 1   # +1 for NUL terminator

    trans_meta = []
    for _, v in pairs:
        b = v.encode('utf-8')
        trans_meta.append((len(b), pos))
        pos += len(b) + 1

    with open(out_path, 'wb') as f:
        # Header (7 × uint32 LE)
        f.write(struct.pack('<7I', 0x950412de, 0, N, O, T, S, H))
        # Original-strings table
        for length, offset in orig_meta:
            f.write(struct.pack('<2I', length, offset))
        # Translated-strings table
        for length, offset in trans_meta:
            f.write(struct.pack('<2I', length, offset))
        # Original string data (NUL-terminated)
        for k, _ in pairs:
            f.write(k.encode('utf-8') + b'\x00')
        # Translated string data (NUL-terminated)
        for _, v in pairs:
            f.write(v.encode('utf-8') + b'\x00')

    print(f'Generated: {out_path}  ({N} entries, {pos} bytes)')


if __name__ == '__main__':
    if len(sys.argv) != 3:
        print('Usage: make-mo.py <input.po> <output.mo>')
        sys.exit(1)
    po_path, mo_path = sys.argv[1], sys.argv[2]
    pairs = parse_po(po_path)
    make_mo(pairs, mo_path)
