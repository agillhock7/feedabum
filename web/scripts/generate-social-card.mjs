import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { Resvg } from '@resvg/resvg-js'

const scriptDir = dirname(fileURLToPath(import.meta.url))
const webRoot = resolve(scriptDir, '..')
const svgPath = resolve(webRoot, 'public/social/fab-social-card.svg')
const pngPath = resolve(webRoot, 'public/social/fab-social-card.png')

const svg = readFileSync(svgPath)
const resvg = new Resvg(svg, {
  fitTo: {
    mode: 'width',
    value: 1200
  }
})

const pngData = resvg.render().asPng()
writeFileSync(pngPath, pngData)

console.log(`Generated ${pngPath}`)
