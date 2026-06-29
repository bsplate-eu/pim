import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import Underline from '@tiptap/extension-underline';
import TextAlign from '@tiptap/extension-text-align';
import Youtube from '@tiptap/extension-youtube';
import TaskList from '@tiptap/extension-task-list';
import TaskItem from '@tiptap/extension-task-item';
import Table from '@tiptap/extension-table';
import TableRow from '@tiptap/extension-table-row';
import TableHeader from '@tiptap/extension-table-header';
import TableCell from '@tiptap/extension-table-cell';
import CodeBlockLowlight from '@tiptap/extension-code-block-lowlight';
import { createLowlight, common } from 'lowlight';

import { FileBlockNode } from './FileBlockNode';
import { LinkPreviewNode } from './LinkPreviewNode';
import { buildMentionExtension } from './MentionNode';

const lowlight = createLowlight(common);

export function buildExtensions(placeholder = 'Wpisz / aby wybrać blok…') {
    return [
        StarterKit.configure({ codeBlock: false }),
        Placeholder.configure({ placeholder }),
        Underline,
        Link.configure({ openOnClick: false, HTMLAttributes: { class: 'text-primary-600 underline' } }),
        Image.configure({ HTMLAttributes: { class: 'rounded-md max-w-full h-auto' } }),
        TextAlign.configure({ types: ['heading', 'paragraph'] }),
        Youtube.configure({ controls: true, nocookie: true, HTMLAttributes: { class: 'rounded-md my-2' } }),
        TaskList,
        TaskItem.configure({ nested: true }),
        Table.configure({ resizable: true }),
        TableRow,
        TableHeader,
        TableCell,
        CodeBlockLowlight.configure({ lowlight }),
        FileBlockNode,
        LinkPreviewNode,
        buildMentionExtension(),
    ];
}
