import QR from 'qrcode';
// Compatibility adapter for existing PHP screens, bundled locally (no CDN).
(window as any).QRCode = class {
  constructor(element: HTMLElement, options: {text:string;width:number;height:number}) {
    const canvas=document.createElement('canvas');
    element.appendChild(canvas);
    QR.toCanvas(canvas, options.text, {width:options.width,margin:2,errorCorrectionLevel:'M'})
      .catch(()=>{element.textContent='Não foi possível gerar o QR. Use o link ou código abaixo.';});
  }
};
