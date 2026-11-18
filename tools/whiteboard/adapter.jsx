import React, { useState } from 'react';
import { createRoot } from 'react-dom/client';
import { Excalidraw, MainMenu, exportToBlob, convertToExcalidrawElements, reconcileElements, CaptureUpdateAction } from '@excalidraw/excalidraw';
import '@excalidraw/excalidraw/index.css';

export { exportToBlob, convertToExcalidrawElements, reconcileElements, CaptureUpdateAction };

export function mount(container, options) {
  return new Promise((resolve) => {
    const root = createRoot(container);
    function Board() {
      const [readOnly, setReadOnly] = useState(options.readOnly);
      return <Excalidraw
        initialData={{ elements: options.elements, appState: { theme: options.theme } }}
        excalidrawAPI={(api) => resolve({ api, setReadOnly, unmount: () => root.unmount() })}
        onChange={options.onChange}
        onPointerUpdate={options.onPointerUpdate}
        langCode="ko-KR"
        viewModeEnabled={readOnly}
        aiEnabled={false}
        validateEmbeddable={() => false}
        UIOptions={{ tools: { image: false }, canvasActions: { loadScene: false, saveToActiveFile: false, export: false, saveAsImage: false } }}
      ><MainMenu><MainMenu.DefaultItems.ClearCanvas /><MainMenu.DefaultItems.ToggleTheme /><MainMenu.DefaultItems.ChangeCanvasBackground /></MainMenu></Excalidraw>;
    }
    root.render(<Board />);
  });
}
