import { Controller } from '@hotwired/stimulus'
import QRCode from 'qrcode'

export default class extends Controller {
    static values = { uri: String }

    connect () {
        QRCode.toCanvas(this.element, this.uriValue, {
            width: 192,
            margin: 2,
            color: { dark: '#000000', light: '#ffffff' },
        })
    }
}
